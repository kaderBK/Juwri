<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Modules\Content\Models\Blog;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoBlogPackTest extends TestCase
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
        config(['settings.blog_enable' => 1]);

        $settings = new class(['theme_color' => '#111', 'site_title' => 'Tag | Brand', 'meta_description' => 'GLOBAL', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png', 'site_admin_logo' => 'logo.png', 'currency_sign' => '$', 'currency_pos' => 1]) {
            public function __construct(private array $data) {}
            public function __get(string $key): mixed { return $this->data[$key] ?? null; }
            public function __set(string $key, mixed $value): void { $this->data[$key] = $value; }
            public function __isset(string $key): bool { return array_key_exists($key, $this->data); }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect(), 'pages' => collect(), 'admin_languages' => collect()]);
        $this->withoutMiddleware([\App\Http\Middleware\Installed::class, \App\Http\Middleware\PreventRequestForgery::class]);
    }

    private function makeBlog(array $attrs = []): Blog
    {
        $author = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        return Blog::create(array_merge([
            'author' => $author->id,
            'title' => 'How to make a QR menu',
            'slug' => 'how-to-make-a-qr-menu',
            'description' => '<p>Long body text that explains everything about QR menus in detail.</p>',
            'image' => 'hero.png',
            'status' => 'publish',
        ], $attrs));
    }

    public function test_single_post_emits_blogposting_dates_and_fallback_description(): void
    {
        $blog = $this->makeBlog();

        $html = $this->get('/blog/'.$blog->id)->assertOk()->getContent();

        $this->assertStringContainsString('content="Long body text that explains everything about QR menus in detail."', $html, 'fallback description = trimmed body');
        $this->assertStringContainsString('<time datetime="'.$blog->created_at->toDateString().'"', $html);
        $this->assertStringContainsString('storage/blog/hero.png', $html);

        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $posting = null;
        foreach ($m[1] as $json) {
            $d = json_decode($json, true);
            $this->assertNotNull($d);
            if (($d['@type'] ?? '') === 'BlogPosting') {
                $posting = $d;
            }
        }
        $this->assertNotNull($posting, 'BlogPosting present');
        $this->assertSame('How to make a QR menu', $posting['headline']);
        $this->assertSame($blog->created_at->toIso8601String(), $posting['datePublished']);
        $this->assertSame($blog->updated_at->toIso8601String(), $posting['dateModified']);
        $this->assertNotEmpty($posting['author']['name']);
    }

    public function test_admin_set_meta_description_wins_over_fallback(): void
    {
        $blog = $this->makeBlog(['meta_description' => 'Hand-written snippet for search.']);

        $html = $this->get('/blog/'.$blog->id)->assertOk()->getContent();

        $this->assertStringContainsString('content="Hand-written snippet for search."', $html);
        $this->assertStringNotContainsString('content="Long body text', $html);
    }

    public function test_admin_update_persists_meta_description(): void
    {
        $admin = User::factory()->create(['group_id' => '1', 'status' => 1, 'email_verified_at' => now(), 'user_type' => 'admin']);
        $blog = $this->makeBlog();
        $category = \Illuminate\Support\Facades\DB::table('blog_categories')->insertGetId(['title' => 'News', 'slug' => 'news']);

        $this->actingAs($admin)->put(route('admin.blogs.update', $blog->id), [
            'title' => $blog->title,
            'slug' => $blog->slug,
            'description' => $blog->description,
            'meta_description' => 'Snippet set from the admin form.',
            'status' => 'publish',
            'category' => [$category],
            'tags' => 'qr,menu',
        ]);

        $this->assertSame('Snippet set from the admin form.', $blog->fresh()->meta_description);
    }
}
