<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Language;
use Juwri\Modules\Content\Models\Blog;
use Tests\TestCase;

class SeoCanonicalTest extends TestCase
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

        $settings = new class(['theme_color' => '#111', 'site_title' => 'TestApp', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
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

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    public function test_blog_id_only_url_canonicalizes_to_slugged_form(): void
    {
        config(['settings.blog_enable' => 1]); // BlogController aborts 404 without it

        $blog = Blog::create([
            'title' => 'Hello SEO', 'slug' => 'hello-seo', 'description' => '<p>Body</p>',
            'image' => 'default.png', 'status' => 'publish',
        ]);

        $response = $this->get('/blog/'.$blog->id);

        $response->assertOk();
        $response->assertSeeHtml('rel="canonical" href="'.route('blog.single', [$blog->id, 'hello-seo']).'"');
    }

    public function test_marketing_page_canonical_is_current_url(): void
    {
        $this->get('/contact')->assertOk()->assertSeeHtml('rel="canonical" href="'.url('/contact').'"');
    }
}
