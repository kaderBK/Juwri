<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\User;
use Juwri\Modules\Content\Models\Blog;
use Tests\TestCase;

class SeoBlogLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);
        // blogHreflang() calls getLocalizedURL directly (independent of
        // include_language_code), so these tests exercise the true-translation
        // hreflang without prefix-mode routing — keep routes in session mode.
        config(['settings.blog_enable' => 1, 'settings.include_language_code' => 0]);
        config(['laravellocalization.supportedLocales' => get_active_languages()]);

        $settings = new class(['theme_color' => '#111', 'site_title' => 'T | Brand', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
        {
            public function __construct(private array $data) {}

            public function __get(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function __set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function __isset(string $key): bool
            {
                return array_key_exists($key, $this->data);
            }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect(), 'pages' => collect()]);
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function makeBlog(array $attrs): Blog
    {
        $author = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        return Blog::create(array_merge([
            'author' => $author->id, 'title' => 'Post title', 'slug' => 'slug-'.uniqid(),
            'description' => '<p>body</p>', 'image' => 'x.png', 'status' => 'publish',
        ], $attrs));
    }

    public function test_index_shows_current_locale_and_legacy_posts_only(): void
    {
        $en = $this->makeBlog(['title' => 'English Post', 'slug' => 'english-post', 'locale' => 'en']);
        $ar = $this->makeBlog(['title' => 'Arabic Post', 'slug' => 'arabic-post', 'locale' => 'ar']);
        $legacy = $this->makeBlog(['title' => 'Legacy Post', 'slug' => 'legacy-post', 'locale' => null]);

        $html = $this->get('/blog')->assertOk()->getContent(); // en locale
        $this->assertStringContainsString('english-post', $html);
        $this->assertStringContainsString('legacy-post', $html, 'legacy locale=null shows everywhere');
        $this->assertStringNotContainsString('arabic-post', $html, 'other-locale post hidden');
    }

    public function test_translation_pair_single_emits_only_sibling_hreflang(): void
    {
        $en = $this->makeBlog(['title' => 'How to', 'slug' => 'how-to-en', 'locale' => 'en', 'translation_group' => 'g1']);
        $ar = $this->makeBlog(['title' => 'كيف', 'slug' => 'how-to-ar', 'locale' => 'ar', 'translation_group' => 'g1']);

        $html = $this->get('/blog/'.$en->id)->assertOk()->getContent();

        preg_match_all('/<link rel="alternate" hreflang="([a-z-]+)"/', $html, $m);
        sort($m[1]);
        $this->assertSame(['ar', 'en'], $m[1], 'only the two true translations, no sitewide set, no x-default');
    }

    public function test_standalone_post_emits_no_alternates(): void
    {
        $blogPost = $this->makeBlog(['slug' => 'standalone', 'locale' => 'en', 'translation_group' => null]);

        $html = $this->get('/blog/'.$blogPost->id)->assertOk()->getContent();

        $this->assertStringNotContainsString('rel="alternate" hreflang', $html, 'no false translation claims for a one-language post');
    }

    public function test_legacy_post_emits_no_alternates(): void
    {
        $blogPost = $this->makeBlog(['slug' => 'legacy', 'locale' => null, 'translation_group' => null]);

        $html = $this->get('/blog/'.$blogPost->id)->assertOk()->getContent();

        $this->assertStringNotContainsString('rel="alternate" hreflang', $html);
    }

    public function test_admin_persists_locale_and_translation_group(): void
    {
        $admin = User::factory()->create(['group_id' => '1', 'status' => 1, 'email_verified_at' => now(), 'user_type' => 'admin']);
        $blog = $this->makeBlog(['slug' => 'to-edit']);
        $cat = DB::table('blog_categories')->insertGetId(['title' => 'N', 'slug' => 'n']);

        $this->actingAs($admin)->put(route('admin.blogs.update', $blog->id), [
            'title' => 'Post title', 'slug' => 'to-edit', 'description' => '<p>b</p>',
            'locale' => 'ar', 'translation_group' => 'grp-x',
            'status' => 'publish', 'category' => [$cat], 'tags' => 't',
        ]);

        $fresh = $blog->fresh();
        $this->assertSame('ar', $fresh->locale);
        $this->assertSame('grp-x', $fresh->translation_group);
    }
}
