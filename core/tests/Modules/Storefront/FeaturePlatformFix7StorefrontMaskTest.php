<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\AbuseHoldWall;
use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use App\Services\Entitlements;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use App\Services\StorefrontToggleMask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\SyncDashboardStore;
use Juwri\Core\Models\Feature;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\EntitlementWriter;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Menu\Models\Menu;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EditionTestHooks;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * F7-11 (feature-platform gap fix): an unentitled store must not RENDER a
 * plan-gated control it cannot honour.
 *
 * Found during FIX-2's F2-4. ~20 theme `index.blade.php` files read
 * `$postOptions->gift_cards_enabled` / `curbside_enabled` /
 * `allow_order_scheduling` directly, so the entitlement was only consulted once
 * the diner tried to ACT. The picker/checkbox/field rendered either way, and the
 * refusal arrived after the diner had committed.
 *
 * Fixed centrally in `StorefrontToggleMask`, applied on the storefront read
 * path, so every theme is covered at once and a new theme cannot reintroduce
 * the bug by copy-pasting the same `!empty($postOptions->…)`. The tests below
 * therefore run across MORE THAN ONE theme on purpose: a per-theme blade fix
 * would have needed 20 edits and would regress on theme #21.
 *
 * The line: the OWNER's own settings form is deliberately NOT masked — see the
 * class docblock. `test_the_owner_settings_form_still_sees_its_own_toggle`
 * pins that, because masking it would silently erase a merchant's saved
 * configuration when a SKU lapsed.
 */
class FeaturePlatformFix7StorefrontMaskTest extends TestCase
{
    use RefreshDatabase;

    /**
     * option name => [feature key, [theme => marker the control renders]].
     *
     * TWO themes per toggle on purpose: the whole point of fixing this
     * centrally is that no blade was edited, so the proof has to be that
     * unrelated themes both changed behavior from one service.
     *
     * `pulse` was the obvious third theme and is deliberately NOT here: its
     * `index.blade.php` line 103 does `$allow_order = !empty($postOptions->allow_order)`,
     * shadowing the controller's computed `$allow_order` with a post_option
     * that does not exist, so pulse's entire cart/checkout sheet never renders
     * and every assertion against it is green for the wrong reason. Reported
     * separately — it is a pre-existing theme bug, not this row's.
     */
    public static function toggles(): array
    {
        return [
            'gift cards' => ['gift_cards_enabled', 'gift_cards', [
                'nebula' => 'nb-gift-card-input',
                'aurora' => 'au-gift-card-code',
            ]],
            'curbside' => ['curbside_enabled', 'curbside', [
                'nebula' => 'id="nb-curbside"',
                'aurora' => 'id="au-curbside"',
            ]],
            'scheduling' => ['allow_order_scheduling', 'scheduled_orders', [
                'nebula' => 'nb-order-sched',
                'aurora' => 'au-scheduled-at',
            ]],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        /* Storefront templates extend a layout fed by a view()->composer that
           ViewServiceProvider only registers when APP_INSTALLED is set, and
           phpunit.xml deliberately leaves it unset. Share it by hand or every
           render dies on "Undefined variable $settings". */
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
            SyncDashboardStore::class,
            EnsureStaffDashboardPermission::class,
            ...EditionTestHooks::middleware(),
            AbuseHoldWall::class,
        ]);

        // FeatureAccess::$storeMemo is a process-lifetime static; a store id
        // reused after RefreshDatabase would otherwise inherit the previous
        // test's verdict and every case here would be green for free.
        FeatureAccess::flush();

        foreach (['gift_cards', 'curbside', 'scheduled_orders'] as $key) {
            $this->planGate($key, true);
            config(['settings.admin_allow_'.$key => 1]);
        }
        FeatureRegistry::clearCache();
    }

    // ------------------------------------------------------------- fixtures

    /** Self-sufficient: does not depend on the gate-flip seeder having run. */
    private function planGate(string $key, bool $on): void
    {
        Feature::updateOrCreate(['key' => $key], ['label' => $key, 'plan_gated' => $on]);
        app()->forgetInstance('features.plan_gated');
        FeatureAccess::flush();
    }

    /**
     * A store with ALL THREE owner-side toggles switched ON, on a plan that
     * grants none of the three SKUs. This is the exact shape the audit
     * described: the merchant configured the feature, then lapsed (or never
     * bought) the add-on.
     *
     * @return array{0:Store,1:User}
     */
    private function store(string $theme): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 10,
            'annual_price' => 100, 'lifetime_price' => 1000, 'status' => 1,
            'settings' => [
                'restaurant_limit' => 5, 'menu_limit' => 999, 'category_limit' => 999,
                'scan_limit' => 999, 'table_qr' => '1', 'allow_ordering' => '1',
            ],
        ]);
        $owner = User::factory()->create([
            'group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(),
        ]);
        $title = 'R '.Str::random(5);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'x', 'description' => 'x',
            'main_image' => 'a.png', 'cover_image' => 'a.png', 'address' => '1 St', 'phone' => '1',
        ]);

        foreach ([
            'store_template' => $theme,
            'restaurant_on_table_order' => '1',
            'restaurant_takeaway_order' => '1',
            'gift_cards_enabled' => '1',
            'curbside_enabled' => '1',
            'allow_order_scheduling' => '1',
        ] as $name => $value) {
            StoreOption::updateStoreOption($store->id, $name, $value);
        }

        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => '1']);
        Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id,
            'name' => 'Club Sandwich', 'price' => 42.00, 'active' => '1',
        ]);

        return [$store, $owner];
    }

    private function entitle(User $owner, string $key): void
    {
        EntitlementWriter::grant($owner, $key, 'admin', null, '1', null, 'f7-11-fixture');
        app()->forgetInstance('features.plan_gated');
        FeatureAccess::flush();
    }

    // ----------------------------------------------------------- unentitled
    /**
     * THE ROW. Same store, same owner-side toggle ON, two different themes —
     * the control must be gone from both, without either blade being edited.
     */
    #[DataProvider('toggles')]
    public function test_an_unentitled_store_does_not_render_the_control(string $option, string $key, array $themes): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        foreach ($themes as $theme => $marker) {
            [$store, $owner] = $this->store($theme);

            $this->assertFalse(
                Entitlements::allows($owner->fresh(), $key),
                "$theme: the fixture owner must start unentitled for $key"
            );
            $this->assertSame(
                '1',
                (string) store_options($store->id, $option),
                "$theme: the owner-side toggle must still be ON in the database — this row is about RENDERING, not about erasing settings"
            );

            $this->get('/'.$store->slug)->assertOk()->assertDontSeeHtml($marker);
        }
    }

    /**
     * The discriminating half: with the entitlement granted, the very same
     * marker comes back. Without this, the test above would pass against a
     * theme that simply never renders the control.
     */
    #[DataProvider('toggles')]
    public function test_an_entitled_store_still_renders_the_control(string $option, string $key, array $themes): void
    {
        foreach ($themes as $theme => $marker) {
            [$store, $owner] = $this->store($theme);
            $this->entitle($owner, $key);

            $this->assertTrue(Entitlements::allows($owner->fresh(), $key), "$theme: fixture");

            $this->get('/'.$store->slug)->assertOk()->assertSeeHtml($marker);
        }
    }

    /**
     * Kill veto wins: an ENTITLED store still loses the control when the
     * platform switch is off. Same two-lane contract as the middleware.
     */
    #[DataProvider('toggles')]
    public function test_the_kill_switch_hides_the_control_even_when_entitled(string $option, string $key, array $themes): void
    {
        foreach ($themes as $theme => $marker) {
            [$store, $owner] = $this->store($theme);
            $this->entitle($owner, $key);

            config(['settings.admin_allow_'.$key => 0]);
            FeatureRegistry::clearCache();
            FeatureAccess::flush();

            $this->get('/'.$store->slug)->assertOk()->assertDontSeeHtml($marker);

            config(['settings.admin_allow_'.$key => 1]);
            FeatureRegistry::clearCache();
            FeatureAccess::flush();
        }
    }

    /**
     * An UNGATED key must behave exactly as before — FeatureAccess
     * short-circuits past the entitlement lane, so the control renders for an
     * unentitled owner. This is what keeps the fix from becoming a stealth
     * paywall on keys nobody decided to monetize.
     */
    #[DataProvider('toggles')]
    public function test_an_ungated_key_is_not_masked(string $option, string $key, array $themes): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        $this->planGate($key, false);

        [$store, $owner] = $this->store(array_key_first($themes));
        $marker = $themes[array_key_first($themes)];

        $this->assertFalse(Entitlements::allows($owner->fresh(), $key));

        $this->get('/'.$store->slug)->assertOk()->assertSeeHtml($marker);
    }

    // ------------------------------------------------------- where the line is

    /**
     * WHERE THE LINE IS DRAWN. The owner's own settings form reads the RAW
     * toggle, unmasked. If the mask reached here, a lapsed SKU would show the
     * merchant their own setting as "off", and re-entitling would come back
     * off — silently destroying configuration they never changed.
     */
    public function test_the_owner_settings_form_still_sees_its_own_toggle(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        [$store, $owner] = $this->store('nebula');

        $this->assertFalse(Entitlements::allows($owner->fresh(), 'gift_cards'));

        // The un-masked read the settings form is fed by.
        $raw = store_options($store->id);
        $this->assertSame('1', (string) $raw->gift_cards_enabled);
        $this->assertSame('1', (string) $raw->curbside_enabled);
        $this->assertSame('1', (string) $raw->allow_order_scheduling);
    }

    /** The mask itself: pure, and it never invents a toggle that was off. */
    public function test_the_mask_leaves_an_already_off_toggle_alone(): void
    {
        [$store] = $this->store('nebula');
        foreach (array_keys(StorefrontToggleMask::GATED_TOGGLES) as $toggle) {
            if (StoreSettings::has((int) $store->id, $toggle)) {
                StoreSettings::set((int) $store->id, $toggle, '0');
            }
        }
        FeatureAccess::flush();

        $masked = StorefrontToggleMask::apply(store_options($store->id), (int) $store->id);

        foreach (array_keys(StorefrontToggleMask::GATED_TOGGLES) as $option) {
            $this->assertEmpty($masked->{$option}, "$option must stay off, not be flipped");
        }
    }

    /** Defensive: a null/none object must not fatal the storefront. */
    public function test_the_mask_is_a_no_op_without_a_restaurant(): void
    {
        $options = (object) ['gift_cards_enabled' => '1'];

        $this->assertSame($options, StorefrontToggleMask::apply($options, null));
        $this->assertSame('1', (string) $options->gift_cards_enabled);
        $this->assertNull(StorefrontToggleMask::apply(null, 1));
    }
}
