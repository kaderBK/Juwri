<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Modules\Storefront\Models\AiThemeProposal;
use App\Models\Theme;
use App\Models\ThemeVersion;
use Juwri\Core\Models\User;
use Juwri\Modules\Ai\Services\AiProviderFactory;
use Juwri\Modules\Ai\Services\AiService;
use App\Services\Ai\E2eStubAiService;
use Juwri\Modules\Storefront\Storefront\MarketplaceThemeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Theme Studio phase D4: superadmin generation against a vertical's demo
 * store, the demo preview, and "save as theme" → a Studio theme package in
 * the review queue (never published from here). Stub AI only.
 */
class ThemeStudioAiAdminTest extends TestCase
{
    use RefreshDatabase;

    private const SLUG = 'ai-admin-test-theme';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->shareLayoutGlobals([], true);
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->app->instance(AiService::class, new E2eStubAiService($this->app->make(AiProviderFactory::class)));
        config(['platform.lighthouse.auto_gate' => false]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/marketplace-themes/'.self::SLUG));
        File::deleteDirectory(public_path('assets/marketplace-themes/'.self::SLUG));
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['group_id' => '1', 'status' => 1, 'email_verified_at' => now(), 'user_type' => 'admin']);
    }

    /** @return array<string,mixed> */
    private function generate(array $body): array
    {
        return $this->actingAs($this->admin())->postJson(route('admin.theme-studio.run'), $body)->assertOk()->json();
    }

    public function test_routes_live_in_the_admin_group(): void
    {
        foreach (['admin.theme-studio.generate', 'admin.theme-studio.run', 'admin.theme-studio.preview', 'admin.theme-studio.save'] as $name) {
            $middleware = Route::getRoutes()->getByName($name)->gatherMiddleware();
            $this->assertContains('admin', $middleware, $name);
            $this->assertContains('csp.dashboard', $middleware, $name);
        }

        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $this->actingAs($owner)->postJson(route('admin.theme-studio.run'), ['vertical' => 'restaurant']);
        $this->assertSame(0, AiThemeProposal::count(), 'a non-admin cannot generate');
    }

    public function test_page_config_lists_the_verticals_and_urls(): void
    {
        $res = $this->actingAs($this->admin())->get(route('admin.theme-studio.generate'))->assertOk();
        $config = $res->viewData('config'); // the page renders the D-ui view inside the admin layout

        $this->assertContains('pharmacy', array_column($config['verticals'], 'type'));
        $this->assertStringContainsString('__SET__', $config['urls']['preview']);
        $this->assertArrayHasKey('generate', $config['urls']);
        $this->assertArrayHasKey('save', $config['urls']);
    }

    public function test_generate_against_an_arabic_pharmacy_demo_and_preview_it(): void
    {
        $res = $this->generate(['vertical' => 'pharmacy', 'lang' => 'ar', 'brief' => ['priority' => 'fast_ordering', 'vibes' => ['modern']]]);

        $this->assertFalse($res['fallback']);
        $this->assertCount(3, $res['proposals']);
        foreach ($res['proposals'] as $p) {
            $this->assertContains('product-grid', array_column($p['layout']['sections'], 'type'));
        }
        $row = AiThemeProposal::where('public_id', $res['set_id'])->firstOrFail();
        $this->assertNull($row->store_id);
        $this->assertSame('pharmacy', $row->vertical);

        $page = $this->actingAs($this->admin())->get(route('admin.theme-studio.preview', ['set' => $res['set_id'], 'proposal' => 'p1']))->assertOk();
        $html = $page->getContent();
        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('mbs-product-grid', $html);
        $this->assertStringContainsString('no-store', (string) $page->headers->get('Cache-Control'));

        $en = $this->actingAs($this->admin())->get(route('admin.theme-studio.preview', ['set' => $res['set_id'], 'proposal' => 'p1', 'lang' => 'en']))->assertOk()->getContent();
        $this->assertStringContainsString('dir="ltr"', $en);

        $this->actingAs($this->admin())->get(route('admin.theme-studio.preview', ['set' => $res['set_id'], 'proposal' => 'p8']))->assertNotFound();
        $this->actingAs($this->admin())->postJson(route('admin.theme-studio.run'), ['vertical' => 'spaceship'])->assertStatus(422);
    }

    public function test_save_packages_a_proposal_into_the_review_queue(): void
    {
        $res = $this->generate(['vertical' => 'restaurant', 'brief' => ['vibes' => ['warm']]]);
        $p = $res['proposals'][0];

        $save = $this->actingAs($this->admin())->postJson(route('admin.theme-studio.save'), [
            'set' => $res['set_id'], 'proposal' => 'p1', 'slug' => self::SLUG, 'name' => 'AI Warm Bistro',
        ])->assertOk();

        $this->assertTrue($save->json('ok'));
        $version = ThemeVersion::findOrFail($save->json('version_id'));
        $this->assertSame((int) $save->json('theme_id'), (int) $version->theme_id);
        $this->assertSame('review', $version->status, json_encode($version->lint_report));
        $this->assertSame('draft', Theme::findOrFail($version->theme_id)->status, 'never published from here');
        $this->assertStringContainsString('theme-reviews', $save->json('review_url'));

        $theme = MarketplaceThemeResolver::resolve('mp:'.self::SLUG.'@1', gate: false);
        $this->assertNotNull($theme);
        $manifest = $theme->manifest();
        $this->assertSame('1', (string) $manifest['library']);
        $this->assertSame('v2', $manifest['commerce_runtime']);
        $this->assertSame($p['recipe_id'], $manifest['recipe']);
        $this->assertSame($p['tokens']['colors.primary'], $manifest['tokens']['colors.primary']);
        $this->assertSame($p['font_pair'], $manifest['tokens']['fonts.pair']);
        $this->assertSame($p['layout']['order'], $theme->templateJson()['order']);
        $this->assertNull(MarketplaceThemeResolver::resolve('mp:'.self::SLUG.'@1'), 'not servable until published');

        $this->actingAs($this->admin())->postJson(route('admin.theme-studio.save'), [
            'set' => $res['set_id'], 'proposal' => 'p1', 'slug' => self::SLUG, 'name' => 'Again',
        ])->assertStatus(422);
        $this->actingAs($this->admin())->postJson(route('admin.theme-studio.save'), [
            'set' => $res['set_id'], 'proposal' => 'p1', 'slug' => 'Bad Slug!', 'name' => 'x',
        ])->assertStatus(422);
    }
}
