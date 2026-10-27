<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Providers\ViewServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Notification;
use Juwri\Core\Settings\PlatformSettings;
use Juwri\Modules\Content\Models\Blog;
use Juwri\Modules\Content\Models\BlogCategory;
use Juwri\Modules\Content\Models\BlogComment;
use Juwri\Modules\Content\Models\Page;
use Juwri\Modules\Content\Models\Testimonial;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * WI-09d characterisation of App\Providers\ViewServiceProvider: the per-request
 * view globals and the admin / frontend composers.
 */
class ViewComposersContractTest extends TestCase
{
    use RefreshDatabase;

    /** Boot the provider again as if serving `$path` on an installed site. */
    private function bootFor(string $path): void
    {
        config(['app.installed' => true, 'app.theme' => 'juwri']);
        $this->app->instance('request', Request::create($path));
        new ViewServiceProvider($this->app)->boot();
    }

    /** A view instance for `$name` (the file need not exist) with its composers applied. */
    private function composed(string $name): array
    {
        $view = new View(view(), view()->getEngineResolver()->resolve('php'), $name, __FILE__, []);
        view()->callComposer($view);

        return $view->getData();
    }

    public function test_globals_mask_secrets_decode_json_and_list_active_languages_by_position(): void
    {
        foreach (['site_title' => 'Juwri', 'social_json' => '{"a":1}', 'stripe_secret_key' => encrypt('sk_test_1234567890abcd')] as $name => $value) {
            PlatformSettings::set($name, $value);
        }
        Language::query()->delete();
        DB::table('languages')->insert([
            ['code' => 'tr', 'name' => 'Turkish', 'direction' => 'ltr', 'active' => 1, 'position' => 2],
            ['code' => 'ar', 'name' => 'Arabic', 'direction' => 'rtl', 'active' => 1, 'position' => 1],
            ['code' => 'de', 'name' => 'German', 'direction' => 'ltr', 'active' => 0, 'position' => 0],
        ]);
        $this->bootFor('/');

        $data = $this->composed('some.view');

        $this->assertSame('Juwri', $data['settings']->site_title);
        $this->assertSame(1, $data['settings']->social_json->a);
        $this->assertStringNotContainsString('sk_test_1234567890abcd', (string) $data['settings']->stripe_secret_key);
        $this->assertNotSame('', (string) $data['settings']->stripe_secret_key);
        $this->assertSame('templates.juwri.', $data['activeTheme']);
        $this->assertSame('assets/templates/juwri/', $data['activeThemeAssets']);
        $this->assertSame(['ar', 'tr'], $data['languages']->pluck('code')->all());
    }

    public function test_an_explicitly_shared_settings_value_is_not_overwritten(): void
    {
        $this->bootFor('/');
        $this->shareLayoutGlobals(['site_title' => 'Mine']);

        $view = new View(view(), view()->getEngineResolver()->resolve('php'), 'another.view', __FILE__, []);
        view()->callComposer($view);

        $this->assertArrayNotHasKey('settings', $view->getData());
        $this->assertSame('Mine', view()->shared('settings')->site_title);
    }

    public function test_frontend_blog_sidebar_and_footer_pages(): void
    {
        $this->bootFor('/blog');
        $cat = BlogCategory::create(['title' => 'News', 'slug' => 'news', 'position' => 1, 'active' => 1]);
        $blogPosts = [];
        foreach (['One' => 'qr,menu', 'Two' => 'menu,pos', 'Three' => '', 'Four' => 'qr', 'Five' => 'kds'] as $title => $tags) {
            $blogPosts[$title] = Blog::create(['title' => $title, 'slug' => strtolower($title), 'description' => 'd', 'image' => 'x.png', 'status' => 'publish', 'tags' => $tags]);
        }
        Blog::create(['title' => 'Hidden', 'slug' => 'hidden', 'description' => 'd', 'image' => 'x.png', 'status' => 'pending', 'tags' => 'secret']);
        DB::table('blog_category_post')->insert([['category_id' => $cat->id, 'blog_id' => $blogPosts['One']->id], ['category_id' => $cat->id, 'blog_id' => $blogPosts['Two']->id]]);
        foreach (range(1, 6) as $i) {
            Testimonial::create(['name' => "T$i", 'designation' => 'd', 'content' => 'c', 'image' => 'x.png']);
        }

        $sidebar = $this->composed('marketing.blog.sidebar');

        $this->assertSame(['News'], $sidebar['blogCategories']->pluck('title')->all());
        $this->assertSame(2, $sidebar['blogCategories']->first()->blogs_count);
        $this->assertSame(['Five', 'Four', 'Three'], $sidebar['recentBlogs']->pluck('title')->all());
        $this->assertSame(['qr', 'menu', 'pos', 'kds'], array_values($sidebar['blogTags']));
        $this->assertCount(5, $sidebar['testimonials']);

        app()->setLocale('en');
        Page::create(['title' => 'Neutral', 'slug' => 'neutral', 'content' => 'c', 'active' => 1, 'translation_lang' => null]);
        Page::create(['title' => 'English', 'slug' => 'english', 'content' => 'c', 'active' => 1, 'translation_lang' => 'en']);
        Page::create(['title' => 'Turkish', 'slug' => 'turkish', 'content' => 'c', 'active' => 1, 'translation_lang' => 'tr']);
        Page::create(['title' => 'Off', 'slug' => 'off', 'content' => 'c', 'active' => 0, 'translation_lang' => null]);

        $footer = $this->composed('templates.juwri.layouts.includes.footer');
        $this->assertEqualsCanonicalizing(['Neutral', 'English'], $footer['pages']->pluck('title')->all());

        $this->assertArrayNotHasKey('admin_languages', $this->composed('any.view'));
    }

    public function test_admin_header_sidebar_users_and_languages(): void
    {
        $this->requiresEditionPackage('behaviour of an edition package (plans, billing, reseller or marketplace)');
        $this->bootFor('/'.admin_url().'/dashboard');
        foreach (range(1, 22) as $i) {
            Notification::create(['title' => "N$i", 'type' => 't', 'link' => null, 'status' => $i <= 3 ? 0 : 1]);
        }
        TestPlans::purge();
        $active = TestPlans::create(['name' => 'Active', 'description' => 'd', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'settings' => [], 'status' => 1]);
        TestPlans::create(['name' => 'Off', 'description' => 'd', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'settings' => [], 'status' => 0]);
        $blog = Blog::create(['title' => 'B', 'slug' => 'b', 'description' => 'd', 'image' => 'x.png', 'status' => 'publish']);
        foreach (range(1, 3) as $i) {
            BlogComment::create(['blog_id' => $blog->id, 'name' => 'n', 'email' => 'e@x.test', 'comment' => 'c', 'active' => '0', 'parent' => 0]);
        }

        $header = $this->composed('admin.includes.header');
        $this->assertCount(20, $header['notifications']);
        $this->assertSame('N22', $header['notifications']->first()->title);
        $this->assertSame(3, $header['totalUnreadNotifications']);
        $this->assertTrue(isset($header['admin_languages']));

        $sidebar = $this->composed('admin.includes.sidebar');
        $this->assertSame(3, $sidebar['totalUnapprovedComments']);
        $this->assertSame(3, $sidebar['totalUnreadNotifications']);

        foreach (range(1, 50) as $i) {
            BlogComment::create(['blog_id' => $blog->id, 'name' => 'n', 'email' => 'e@x.test', 'comment' => 'c', 'active' => '0', 'parent' => 0]);
        }
        $this->assertSame('50+', $this->composed('admin.includes.sidebar')['totalUnapprovedComments']);

        $this->assertSame([$active->id], $this->composed('admin.users.userdetails')['plans']->pluck('id')->all());
    }
}
