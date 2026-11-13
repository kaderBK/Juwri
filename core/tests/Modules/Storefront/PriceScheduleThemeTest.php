<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\PriceSchedule;
use App\Models\StoreOption;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Services\Availability\MenuScheduleService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * K8 — Dynamic day-part / happy-hour pricing: the strike-through display.
 *
 * The pricing ENGINE (PriceSchedule + MenuScheduleService::decorate) already
 * snapshots the scheduled price and exposes $menu->original_price so a theme can
 * render "was X, now Y". Modern themes already read it; this suite proves the six
 * keep-list themes that were MISSING the display now render the reference price
 * during an active window — server-rendered themes as a struck `<del>` and
 * JS-rendered themes by carrying `original_price` in the payload — and render a
 * single price with no reference when no schedule is live.
 *
 * Money is untouched: decorate() only rewrites the in-memory display; the order
 * path re-resolves server-side (asserted in MenuSchedulesTest).
 */
class PriceScheduleThemeTest extends TestCase
{
    use RefreshDatabase;

    /** Server-rendered cards: the strike-through is a `<del class="…-was">`. */
    private const SERVER_THEMES = [
        'vivid' => 'vd-was',
        'feast' => 'ff-card-was',
        'velvet' => 'v-card-was',
    ];

    /** JS-rendered cards: the reference price rides the item payload. */
    private const JS_THEMES = ['dough', 'picnic', 'crumb'];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['settings.admin_allow_menu_schedules' => true]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();
        MenuScheduleService::flush();

        // Same layout stub the other storefront-render suites use (ViewServiceProvider
        // only shares $settings when APP_INSTALLED is set, which phpunit leaves unset).
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
        view()->share([
            'activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true),
            'settings' => $settings, 'languages' => collect(), 'pages' => collect(),
        ]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        MenuScheduleService::flush();
        parent::tearDown();
    }

    private function storeOnTheme(string $theme): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);
        StoreOption::updateStoreOption($store->id, 'timezone', 'UTC');
        StoreOption::updateStoreOption($store->id, 'store_template', $theme);
        MenuScheduleService::flush();

        return $store;
    }

    private function seedItem(Store $store): Menu
    {
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Drinks']);

        return Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id,
            'name' => 'ZzMojito', 'price' => 10, 'active' => '1',
        ]);
    }

    private function activeHappyHour(Store $store): PriceSchedule
    {
        $s = PriceSchedule::create([
            'store_id' => $store->id, 'name' => 'HH', 'label' => 'Happy Hour',
            'scope' => 'store', 'adjustment_type' => 'percent', 'adjustment_value' => 20,
            'active' => true,
        ]);
        MenuScheduleService::flush();

        return $s;
    }

    /* ─────────────────────── server-rendered themes ─────────────────────── */

    public function test_server_themes_strike_through_the_original_price_during_a_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-08 15:00', 'UTC'));

        foreach (self::SERVER_THEMES as $theme => $wasClass) {
            $store = $this->storeOnTheme($theme);
            $this->seedItem($store);
            $this->activeHappyHour($store);

            $html = $this->get('/'.$store->slug)->assertOk()->getContent();

            $this->assertStringContainsString('ZzMojito', $html, "$theme should render the item");
            $this->assertStringContainsString($wasClass, $html, "$theme should render the struck reference price during a live schedule");
            // scheduled 20%-off price (8) is shown; the reference (10) is the struck one.
            $this->assertStringContainsString(price_symbol_format(10), $html, "$theme should show the original 10");
            $this->assertStringContainsString(price_symbol_format(8), $html, "$theme should show the scheduled 8");
        }
    }

    public function test_server_themes_show_no_reference_price_without_a_schedule(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-08 15:00', 'UTC'));

        foreach (self::SERVER_THEMES as $theme => $wasClass) {
            $store = $this->storeOnTheme($theme);
            $this->seedItem($store);
            // no schedule created

            $html = $this->get('/'.$store->slug)->assertOk()->getContent();

            $this->assertStringContainsString('ZzMojito', $html, "$theme should render the item");
            $this->assertStringNotContainsString($wasClass, $html, "$theme must not render a reference price with no active schedule");
        }
    }

    /* ────────────────────────── JS-rendered themes ──────────────────────── */

    public function test_js_themes_carry_original_price_in_the_payload_during_a_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-08 15:00', 'UTC'));

        foreach (self::JS_THEMES as $theme) {
            $store = $this->storeOnTheme($theme);
            $this->seedItem($store);
            $this->activeHappyHour($store);

            $html = $this->get('/'.$store->slug)->assertOk()->getContent();

            $this->assertStringContainsString('ZzMojito', $html, "$theme should render the item");
            $this->assertMatchesRegularExpression('/"original_price"\s*:\s*"?10(\.0+)?"?/', $html, "$theme payload should carry the reference price during a live schedule");
            $this->assertMatchesRegularExpression('/"price"\s*:\s*"?8(\.0+)?"?/', $html, "$theme payload should carry the scheduled 8");
        }
    }

    public function test_js_themes_carry_null_original_price_without_a_schedule(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-08 15:00', 'UTC'));

        foreach (self::JS_THEMES as $theme) {
            $store = $this->storeOnTheme($theme);
            $this->seedItem($store);

            $html = $this->get('/'.$store->slug)->assertOk()->getContent();

            $this->assertStringContainsString('ZzMojito', $html, "$theme should render the item");
            $this->assertMatchesRegularExpression('/"original_price"\s*:\s*null/', $html, "$theme payload should carry a null reference with no active schedule");
        }
    }
}
