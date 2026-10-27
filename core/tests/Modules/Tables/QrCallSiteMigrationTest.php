<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Tables;

use App\Http\Middleware\AbuseHoldWall;
use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\StoreOption;
use App\Models\StoreTable;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\SyncDashboardStore;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Pos\Models\SignageScreen;
use Juwri\Modules\Tables\Models\QrDesign;
use Tests\Support\EditionTestHooks;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * PARITY-RUN T10-3: every legacy QR surface still renders after the migration
 * onto QrRenderService.
 *
 * This is the risky half of the track — the renderer under `qr_svg()` and under
 * `StoreController@qrSvg` was replaced wholesale — so there is one regression per
 * migrated call site, not one "it renders somewhere" smoke test.
 */
class QrCallSiteMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        config([
            'settings.admin_allow_qr_designer' => true,
            'settings.admin_allow_kiosk' => true,
            'settings.admin_allow_digital_signage' => true,
            'settings.admin_allow_fiscalization' => true,
            'settings.admin_allow_fiscal_sa' => true,
        ]);

        $settings = new class(['theme_color' => '#111', 'site_title' => 'T', 'meta_description' => 'T', 'social_share_image' => 'd.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'f.png'])
        {
            public function __construct(private array $d) {}

            public function __get($k): mixed
            {
                return $this->d[$k] ?? null;
            }

            public function __set($k, $v): void
            {
                $this->d[$k] = $v;
            }

            public function __isset($k): bool
            {
                return array_key_exists($k, $this->d);
            }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect(), 'pages' => collect()]);

        /* SELECTIVE, not blanket: a blanket withoutMiddleware() also drops
           SubstituteBindings, and every route here binds {store} — the
           controller would then see an empty Post and 404. */
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
            SyncDashboardStore::class,
            ...EditionTestHooks::middleware(),
            AbuseHoldWall::class,
        ]);
    }

    /** @return array{0: User, 1: Store} */
    private function ownerRestaurant(): array
    {
        // `table_qr` is a plan feature — StoreController@tables 404s without it.
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => ['restaurant_limit' => 999, 'table_qr' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'Diner Co', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$owner, $store];
    }

    private function brand(Store $store): QrDesign
    {
        return QrDesign::create([
            'store_id' => $store->id, 'is_default' => true, 'name' => 'Default',
            'fg_color' => '#0f766e', 'bg_color' => '#ffffff', 'eye_color' => '#0f766e',
            'dot_style' => 'rounded', 'eye_style' => 'rounded', 'quiet_zone' => 4, 'size' => 512,
        ]);
    }

    /* ── Helper.php: qr_svg() — the three historical callers ────────────────── */

    public function test_qr_svg_helper_still_returns_an_svg(): void
    {
        $svg = qr_svg('https://example.test/x');

        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringContainsString('</svg>', $svg);
    }

    public function test_qr_svg_returns_an_empty_string_on_bad_input_instead_of_throwing(): void
    {
        // Empty content is the one input BaconQrCode refuses outright.
        $this->assertSame('', qr_svg(''));
    }

    /* ── Call site 1: kiosk admin ───────────────────────────────────────────── */

    public function test_kiosk_admin_page_still_renders_its_qr(): void
    {
        [$owner, $store] = $this->ownerRestaurant();
        $this->brand($store);
        $res = $this->actingAs($owner)->get(route('stores.kiosk.index').'?store_id='.$store->id)->assertOk();

        $res->assertSeeHtml('<svg');
        $res->assertSeeHtml('#0f766e'); // and it wears the store's design now
    }

    /* ── Call site 2: signage admin ─────────────────────────────────────────── */

    public function test_signage_admin_page_still_renders_its_qr(): void
    {
        [$owner, $store] = $this->ownerRestaurant();
        $this->brand($store);
        SignageScreen::create([
            'store_id' => $store->id, 'kind' => 'menu',
            'token' => SignageScreen::newToken(), 'active' => true,
        ]);
        $res = $this->actingAs($owner)->get(route('stores.signage.index').'?store_id='.$store->id)->assertOk();

        $res->assertSeeHtml('<svg');
        $res->assertSeeHtml('#0f766e');
    }

    /* ── Call site 3: fiscal receipt block ──────────────────────────────────── */

    public function test_fiscal_receipt_qr_still_renders_and_stays_monochrome(): void
    {
        [, $store] = $this->ownerRestaurant();
        StoreOption::updateStoreOption($store->id, 'fiscal_country', 'SA');
        StoreOption::updateStoreOption($store->id, 'fiscal_vat_number', '300000000000003');
        StoreOption::updateStoreOption($store->id, 'fiscal_legal_name', 'Diner Co');
        $this->brand($store);

        $order = Order::create(['store_id' => $store->id, 'type' => 'takeaway', 'status' => 'new']);
        $html = View::make('fiscal.receipt-block', ['store' => $store->fresh(), 'order' => $order])->render();

        $this->assertStringContainsString('<svg', $html);
        // A compliance QR on a 1-bit thermal head must never inherit brand colour.
        $this->assertStringContainsString('#000000', $html);
        $this->assertStringNotContainsString('#0f766e', $html);
    }

    /* ── Call site 4: StoreController@qrSvg (store / table / branch) ─────────── */

    public function test_qr_svg_endpoint_renders_for_the_store(): void
    {
        [$owner, $store] = $this->ownerRestaurant();
        $this->actingAs($owner)
            ->get(route('stores.qrSvg', $store))
            ->assertOk()->assertHeader('Content-Type', 'image/svg+xml')->assertSeeHtml('<svg');
    }

    public function test_qr_svg_endpoint_renders_for_a_table_and_a_branch(): void
    {
        [$owner, $store] = $this->ownerRestaurant();
        $table = StoreTable::create(['store_id' => $store->id, 'table_no' => 7, 'key' => Str::random(13)]);
        $branch = Branch::create(['store_id' => $store->id, 'name' => 'B1', 'position' => 1]);
        $forTable = $this->actingAs($owner)
            ->get(route('stores.qrSvg', $store).'?table='.$table->key)->assertOk();
        $forBranch = $this->actingAs($owner)
            ->get(route('stores.qrSvg', $store).'?branch_id='.$branch->id)->assertOk();

        $this->assertStringStartsWith('<svg', $forTable->getContent());
        $this->assertStringStartsWith('<svg', $forBranch->getContent());
        $this->assertNotSame($forTable->getContent(), $forBranch->getContent());
    }

    /** The legacy fg/bg/padding overrides still beat the saved design. */
    public function test_qr_svg_endpoint_honours_the_legacy_colour_overrides(): void
    {
        [$owner, $store] = $this->ownerRestaurant();
        $this->brand($store);
        $content = $this->actingAs($owner)
            ->get(route('stores.qrSvg', $store).'?fg=%23112233&bg=%23ffffff&padding=2')
            ->assertOk()->getContent();

        $this->assertStringContainsString('#112233', $content);
        $this->assertStringNotContainsString('#0f766e', $content);
    }

    public function test_qr_svg_endpoint_survives_junk_colours(): void
    {
        [$owner, $store] = $this->ownerRestaurant();
        $this->actingAs($owner)
            ->get(route('stores.qrSvg', $store).'?fg=<script>&bg=nope&padding=99')
            ->assertOk()->assertHeader('Content-Type', 'image/svg+xml')->assertSeeHtml('<svg');
    }

    /* ── Call site 5+6: the pages that embed those endpoints ────────────────── */

    public function test_legacy_qr_builder_page_still_renders(): void
    {
        [$owner, $store] = $this->ownerRestaurant();
        $this->actingAs($owner)
            ->get(route('stores.qrDesigner', $store))
            ->assertOk()->assertSee('QR Designer')->assertSeeHtml(route('stores.qrSvg', $store));
    }

    public function test_branch_qr_page_still_renders(): void
    {
        [$owner, $store] = $this->ownerRestaurant();
        Branch::create(['store_id' => $store->id, 'name' => 'B1', 'position' => 1]);
        $this->actingAs($owner)
            ->get(route('branches.qr', $store->id))->assertOk()->assertSeeHtml(route('stores.qrSvg', $store));
    }

    public function test_tables_page_offers_the_new_qr_actions(): void
    {
        [$owner, $store] = $this->ownerRestaurant();
        StoreTable::create(['store_id' => $store->id, 'table_no' => 1, 'key' => Str::random(13)]);
        $res = $this->actingAs($owner)->get(route('stores.tables', $store))->assertOk();

        $res->assertSee('QR Designer');
        $res->assertSee('Regenerate');
        $res->assertSeeHtml(route('qr.designer.rotate'));
    }

    /** Killing the suite must hide the new controls, never break the old page. */
    public function test_tables_page_hides_the_designer_controls_when_the_suite_is_killed(): void
    {
        config(['settings.admin_allow_qr_designer' => false]);
        FeatureRegistry::clearCache();

        [$owner, $store] = $this->ownerRestaurant();
        StoreTable::create(['store_id' => $store->id, 'table_no' => 1, 'key' => Str::random(13)]);
        $res = $this->actingAs($owner)->get(route('stores.tables', $store))->assertOk();

        /* Assert on the ROUTES, not the label: juwri_nav_model() memoizes per
           route name for the whole PHP process, so a sibling test that rendered
           this same route can leave the sidebar's (cached) entry behind. The
           page's own controls are what this test is about. */
        $res->assertDontSeeHtml(route('qr.designer.rotate'));
        $res->assertDontSeeHtml(route('qr.designer.download'));
        $res->assertSee('Copy link'); // the legacy actions are untouched
    }
}
