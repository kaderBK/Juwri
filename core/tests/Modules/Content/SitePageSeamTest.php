<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Models\Site;
use App\Models\SitePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\User;
use Juwri\Modules\Content\Services\SitePages\SiteContext;
use Tests\TestCase;

/**
 * HOME-PAGES HP-14/HP-17: pins the '/' seam order —
 * disable_landing_page > auth redirect > published site home > login.
 * (Reseller-host precedence sits above all of these in HomeController@index;
 * it resolves by request host and is exercised by the reseller suite.)
 * A missing platform site does not render the marketing home.
 */
class SitePageSeamTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shareLayoutGlobals();
        // APP_INSTALLED=false in phpunit.xml → '/' would bounce to /install.
        config(['app.installed' => true]);
    }

    private function publishHome(): SitePage
    {
        $site = Site::create(['key' => 'seam-site', 'name' => 'Seam', 'active' => true]);

        return SitePage::create([
            'site_id' => $site->id,
            'type' => 'home',
            'status' => 'published',
            'published_blocks' => ['version' => 1, 'blocks' => []],
            'seo' => ['meta_title' => ['en' => 'Block Home Title']],
        ]);
    }

    public function test_published_site_home_renders_instead_of_legacy(): void
    {
        $this->publishHome();
        SiteContext::flushMemo();

        $res = $this->get('/');

        $res->assertOk();
        $res->assertSeeHtml('<title>Block Home Title</title>');
    }

    public function test_unpublished_home_falls_through_to_login(): void
    {
        $page = $this->publishHome();
        $page->update(['status' => 'draft']);
        SiteContext::flushMemo();
        cache()->forget('site:default');

        $res = $this->get('/');

        $res->assertRedirect(route('login'));
        $res->assertDontSee('Block Home Title');
        $res->assertDontSee('Start free', false);
    }

    public function test_no_site_row_falls_through_to_login(): void
    {
        SiteContext::flushMemo();
        cache()->forget('site:default');

        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_disable_landing_page_kill_switch_beats_site_page(): void
    {
        $this->publishHome();
        SiteContext::flushMemo();
        config(['settings.disable_landing_page' => true]);

        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_published_slug_page_wins_over_legacy_page_cms(): void
    {
        $site = Site::create(['key' => 'seam-site2', 'name' => 'Seam2', 'active' => true]);
        SitePage::create([
            'site_id' => $site->id,
            'type' => 'page',
            'slug' => 'about-us',
            'status' => 'published',
            'published_blocks' => ['version' => 1, 'blocks' => []],
            'seo' => ['meta_title' => ['en' => 'Block About Title']],
        ]);
        SiteContext::flushMemo();

        $res = $this->get('/page/about-us');

        $res->assertOk();
        $res->assertSeeHtml('<title>Block About Title</title>');
    }

    public function test_unknown_slug_still_404s_via_legacy_path(): void
    {
        SiteContext::flushMemo();
        cache()->forget('site:default');

        $this->get('/page/definitely-not-a-page')->assertNotFound();
    }

    public function test_authed_user_redirects_to_dashboard_before_site_page(): void
    {
        $this->publishHome();
        SiteContext::flushMemo();

        $user = User::factory()->create(['status' => 1]); // status 0 = banned-middleware logout

        $this->actingAs($user)->get('/')->assertRedirect(route('dashboard'));
    }
}
