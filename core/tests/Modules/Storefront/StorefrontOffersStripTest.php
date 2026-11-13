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
use Juwri\Modules\Loyalty\Models\PriceScheduleTarget;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Services\Availability\MenuScheduleService;
use Juwri\Modules\Storefront\Services\Theme\KeptTemplates;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * The shared offers strip: the items a store is discounting RIGHT NOW.
 *
 * The strip has no data of its own — it filters `$total_menus` for the runtime
 * `original_price` that MenuScheduleService::decorate() sets inside a live
 * PriceSchedule window. So what needs pinning is not "does it render" but
 * WHICH facts make it appear: it must follow the schedule's window rather than
 * the schedule's existence, it must stay off the ~40 legacy templates whose
 * layout it would inject markup into, and it must render nothing at all rather
 * than an empty section when a store has no offers on.
 *
 * Placement and visibility are browser facts and live in the e2e journey
 * tests/e2e/journeys/storefront-offers-strip.js — a server test cannot tell a
 * rendered strip from one buried under a theme's fixed chrome, which is exactly
 * how the first two placements passed while being invisible.
 */
class StorefrontOffersStripTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['settings.admin_allow_menu_schedules' => true]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();
        MenuScheduleService::flush();

        /* ViewServiceProvider only shares $settings when APP_INSTALLED is set and
           phpunit.xml leaves it unset, so a storefront render dies on an
           undefined variable. Same stub the other storefront suites use. */
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

    private function storeOnTheme(string $theme = 'crave'): Store
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

    private function item(Store $store, string $name = 'ZzMojito', float $price = 10, bool $soldOut = false): Menu
    {
        $cat = MenuCategory::firstOrCreate(['store_id' => $store->id, 'name' => 'Drinks']);

        return Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id, 'name' => $name,
            'price' => $price, 'active' => '1', 'image' => 'default.png',
            'is_sold_out' => $soldOut,
        ]);
    }

    /** A 20%-off rule on specific items. `$window` is [days, from, to]. */
    private function happyHour(Store $store, array $items, array $window = []): PriceSchedule
    {
        $schedule = PriceSchedule::create([
            'store_id' => $store->id, 'name' => 'HH', 'label' => 'Happy Hour',
            'scope' => 'item', 'adjustment_type' => 'percent', 'adjustment_value' => 20,
            'days' => $window[0] ?? null, 'starts_at' => $window[1] ?? null, 'ends_at' => $window[2] ?? null,
            'active' => true,
        ]);
        foreach ($items as $item) {
            PriceScheduleTarget::create([
                'schedule_id' => $schedule->id, 'target_type' => 'item', 'target_id' => $item->id,
            ]);
        }
        MenuScheduleService::flush();

        return $schedule;
    }

    // ---------------------------------------------------------------- positive

    public function test_a_live_discount_renders_the_strip_with_both_prices(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-08 15:00', 'UTC'));

        $store = $this->storeOnTheme();
        $item = $this->item($store);
        $this->happyHour($store, [$item]);

        $html = $this->get('/'.$store->slug)->assertOk()->getContent();

        $this->assertStringContainsString('id="mbOffersStrip"', $html);
        $this->assertStringContainsString('data-mbo-item="'.$item->id.'"', $html);
        // The discounted price in mbo-now and the struck original in mbo-was, plus
        // the discount. The sign is bidi-isolate-wrapped (cur_isolate) for correct
        // RTL placement, so assert the amounts in their cells rather than a sign-glued
        // string.
        // The discounted price lives in mbo-now, the struck original in mbo-was. The
        // sign is bidi-isolate-wrapped (cur_isolate) for correct RTL placement, so
        // assert the amount inside each cell rather than a sign-glued literal.
        // MC-34 added a data-mbo-base attribute to each price cell, so allow any
        // attributes after the class before the tag closes.
        preg_match('/<span class="mbo-now"[^>]*>(.*?)<\/span>/', $html, $mNow);
        preg_match('/<del class="mbo-was"[^>]*>(.*?)<\/del>/', $html, $mWas);
        $this->assertStringContainsString('8.00', $mNow[1] ?? '', 'discounted price in mbo-now');
        $this->assertStringContainsString('10.00', $mWas[1] ?? '', 'original price in mbo-was');
        $this->assertStringContainsString('20%', $html);

        // MC-34: the strip carries the base amount + base-currency code so it can
        // follow the diner's display-currency choice like the rest of the menu.
        $this->assertStringContainsString('data-mbo-base-cur=', $html, 'base currency for conversion');
        $this->assertMatchesRegularExpression('/data-mbo-base="8(\.0+)?"/', $html, 'raw base amount for conversion');
    }

    /** Every retained template gets it; the strip is shared, not per-theme. */
    public function test_the_strip_renders_on_every_retained_template(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-08 15:00', 'UTC'));

        foreach (KeptTemplates::MODERN as $theme) {
            $store = $this->storeOnTheme($theme);
            $item = $this->item($store);
            $this->happyHour($store, [$item]);

            $html = $this->get('/'.$store->slug)->assertOk()->getContent();
            $this->assertStringContainsString('id="mbOffersStrip"', $html, "$theme should render the offers strip");
        }
    }

    // ---------------------------------------------------------------- negative

    public function test_no_offers_renders_no_strip_at_all(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-08 15:00', 'UTC'));

        $store = $this->storeOnTheme();
        $this->item($store);
        // No schedule at all.

        $html = $this->get('/'.$store->slug)->assertOk()->getContent();

        // Not "an empty strip" — no strip, and none of its styles or script either.
        $this->assertStringNotContainsString('mbOffersStrip', $html);
        $this->assertStringNotContainsString('mbo-card', $html);
    }

    /**
     * The discriminating case. A schedule that EXISTS but is outside its window
     * discounts nothing, and a strip keyed off the schedule rather than off
     * decorate()'s runtime output would advertise a price the store is not
     * charging.
     */
    public function test_a_schedule_outside_its_window_renders_no_strip(): void
    {
        // Store clock 15:00; the rule only runs 02:00–04:00.
        Carbon::setTestNow(Carbon::parse('2026-07-08 15:00', 'UTC'));

        $store = $this->storeOnTheme();
        $item = $this->item($store);
        $this->happyHour($store, [$item], [null, '02:00:00', '04:00:00']);

        $html = $this->get('/'.$store->slug)->assertOk()->getContent();

        $this->assertStringNotContainsString('mbOffersStrip', $html);
    }

    /** A discounted item nobody can order is a dead card, so it is left out. */
    public function test_a_sold_out_discounted_item_is_excluded(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-08 15:00', 'UTC'));

        $store = $this->storeOnTheme();
        $sold = $this->item($store, 'ZzSoldOut', 10, true);
        $live = $this->item($store, 'ZzInStock', 20);
        $this->happyHour($store, [$sold, $live]);

        $html = $this->get('/'.$store->slug)->assertOk()->getContent();

        $this->assertStringContainsString('data-mbo-item="'.$live->id.'"', $html);
        $this->assertStringNotContainsString('data-mbo-item="'.$sold->id.'"', $html);
    }

    /**
     * WI-03: the legacy templates were removed; a store still saved on one
     * renders the fallback template, strip included.
     */
    public function test_store_on_a_removed_template_gets_the_strip_via_the_fallback(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-08 15:00', 'UTC'));

        $store = $this->storeOnTheme('old-2-theme');
        $item = $this->item($store);
        $this->happyHour($store, [$item]);

        $html = $this->get('/'.$store->slug)->assertOk()->getContent();

        $this->assertStringContainsString('mbOffersStrip', $html);
    }
}
