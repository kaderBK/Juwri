<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Analytics;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Models\UserOption;
use Juwri\Modules\Analytics\Mail\WeeklyDigestMail;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * The analytics CSV export formatted money to the store's currency precision; the
 * screen and the digest email hardcoded 2. So a single-store OMR owner read
 * "1,234.56" on the dashboard and "1,234.567" in the export of the same period —
 * one feature disagreeing with itself. 92 owners are on a 3-decimal currency with
 * real orders.
 *
 * The contract these tests defend:
 *   - screen and CSV agree, whatever the store's currency
 *   - a 2-decimal store's figures do NOT move — that is the whole point of being
 *     currency-aware rather than blanket 3dp
 *   - the digest email agrees with both
 */
class AnalyticsCurrencyDecimalsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        $settings = new class(['theme_color' => '#111111', 'site_title' => 'juwri', 'meta_description' => 'Test description', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
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

        $plan = TestPlans::create([
            'name' => 'Free Plan',
            'description' => 'Free membership plan',
            'monthly_price' => 0,
            'annual_price' => 0,
            'lifetime_price' => 0,
            'settings' => [
                'allow_viewonly' => '1',
                'allow_pro' => '1',
            ],
            'status' => 1,
        ]);

        view()->share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'settings' => $settings,
            'languages' => collect(),
            'admin_languages' => collect(),
            'plan' => $plan,
        ]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);

        // The analytics screen defaults to the current calendar month, and every
        // fixture here is dated relative to now() (storeWithRevenue defaults to
        // now()->subDay()). On the 1st of a month that order lands in the previous
        // month, falls outside the window, and the page renders its empty state —
        // so freeze to a mid-month midday instant. tearDown restores real time.
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::create(2026, 6, 15, 12, 0, 0));
    }

    protected function tearDown(): void
    {
        \Illuminate\Support\Carbon::setTestNow();
        parent::tearDown();
    }

    /** A store priced in $currency, with one paid order of $total. */
    private function storeWithRevenue(User $owner, string $currency, string $sign, float $total, ?Carbon $when = null): Store
    {
        $store = Store::create([
            'user_id' => $owner->id,
            'title' => 'Store '.$currency,
            'slug' => 'store-'.strtolower($currency),
            'color' => '#111', 'sub_title' => 'Test', 'description' => 'Test',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 Test St', 'phone' => '1234567890',
        ]);

        StoreOption::updateStoreOption($store->id, 'currency_code', $currency);
        StoreOption::updateStoreOption($store->id, 'currency_sign', $sign);

        $category = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        $item = Menu::create([
            'store_id' => $store->id, 'category_id' => $category->id,
            'name' => 'Mixed Grill', 'price' => $total, 'active' => '1',
        ]);

        // Order::total is computed from the items, not a column.
        $order = Order::create([
            'store_id' => $store->id,
            'status' => 'completed',
            'is_paid' => 1,
            'created_at' => $when ?? now()->subDay(),
        ]);

        OrderItem::create([
            'order_id' => $order->id,
            'item_id' => $item->id,
            'quantity' => 1,
        ]);

        return $store;
    }

    public function test_a_three_decimal_store_sees_three_decimals_on_screen(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $this->storeWithRevenue($owner, 'OMR', 'OMR', 1234.567);

        $this->actingAs($owner);
        $html = $this->get(route('analytics.index'))->assertStatus(200)->getContent();

        // Digit-boundary: "1,234.567" trivially contains "1,234.57", so a plain
        // substring check can never fail and would prove nothing.
        $this->assertMatchesRegularExpression('/1,234\.567(?![0-9])/', $html, 'the screen rounded a 3-decimal revenue to 2');
        $this->assertDoesNotMatchRegularExpression('/1,234\.57(?![0-9])/', $html);
    }

    public function test_the_screen_and_the_csv_agree_for_a_three_decimal_store(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $this->storeWithRevenue($owner, 'KWD', 'KD', 1234.567);

        $this->actingAs($owner);

        $html = $this->get(route('analytics.index'))->assertStatus(200)->getContent();
        $csv = $this->get(route('analytics.export', ['range' => '7_days']))
            ->assertStatus(200)->streamedContent();

        // the bug: these two disagreed for the same period
        $this->assertMatchesRegularExpression('/1,234\.567(?![0-9])/', $html);
        $this->assertMatchesRegularExpression('/1,234\.567(?![0-9])/', $csv);
    }

    public function test_a_two_decimal_store_is_unchanged(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $this->storeWithRevenue($owner, 'USD', '$', 1234.56);

        $this->actingAs($owner);
        $html = $this->get(route('analytics.index'))->assertStatus(200)->getContent();

        $this->assertMatchesRegularExpression('/1,234\.56(?![0-9])/', $html);
        $this->assertDoesNotMatchRegularExpression('/1,234\.56[0-9]/', $html, 'a 2-decimal store grew a third decimal');
    }

    public function test_the_weekly_digest_follows_the_stores_currency(): void
    {
        Mail::fake();

        $owner = User::factory()->create(['status' => 1, 'email' => 'omr@example.com']);
        // the digest reports LAST week, not the trailing 7 days
        $this->storeWithRevenue($owner, 'OMR', 'OMR', 1234.567, now()->subWeek()->startOfWeek()->addDay());
        UserOption::updateUserOption($owner->id, 'weekly_digest_email', '1');

        $this->artisan('analytics:send-weekly-digest')->assertExitCode(0);

        Mail::assertQueued(WeeklyDigestMail::class, function ($mail) {
            return (int) $mail->currencyDecimals === 3;
        });

        // Render the mail body itself — the property being right proves nothing
        // if the template still hardcodes 2.
        $sent = null;
        Mail::assertQueued(WeeklyDigestMail::class, function ($mail) use (&$sent) {
            $sent = $mail;

            return true;
        });
        $this->assertMatchesRegularExpression('/1,234\.567(?![0-9])/', $sent->render());
    }
}
