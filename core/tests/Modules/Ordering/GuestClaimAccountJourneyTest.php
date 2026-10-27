<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Models\Customer;
use App\Models\Order;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use App\Services\GuestOrderSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GOL E4 (rev/guest-order-linking): end-to-end — an order placed as a guest
 * shows up in the account panel after the diner registers in the same browser.
 * account() already keys history on customer_id; the claim wiring is what makes
 * the earlier guest order carry that id, so this proves the whole path.
 */
class GuestClaimAccountJourneyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $settings = new class(['theme_color' => '#111', 'site_title' => 'Test', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png']) {
            public function __construct(private array $data) {}
            public function __get(string $key): mixed { return $this->data[$key] ?? null; }
            public function __set(string $key, mixed $value): void { $this->data[$key] = $value; }
            public function __isset(string $key): bool { return array_key_exists($key, $this->data); }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect(), 'pages' => collect()]);

        $this->withoutMiddleware([
            \App\Http\Middleware\Installed::class,
            \App\Http\Middleware\PreventRequestForgery::class,
            \App\Http\Middleware\EnsureStaffDashboardPermission::class,
        ]);
        Mail::fake();
    }

    private function makeRestaurant(): Store
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000, 'settings' => ['restaurant_limit' => 5, 'allow_ordering' => '1'], 'status' => 1]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);

        return Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_guest_order_appears_in_account_after_register(): void
    {
        $r = $this->makeRestaurant();

        // Guest checkout made a shadow + order and stamped the session.
        $shadow = Customer::create(['store_id' => $r->id, 'name' => 'Guest', 'email' => 'guest@test.com', 'source' => 'checkout']);
        $order = Order::create(['store_id' => $r->id, 'customer_id' => $shadow->id, 'customer_name' => 'Guest', 'track_token' => Str::random(12)]);

        // Register in the same browser (adopt branch — session proves the shadow).
        $this->withSession([GuestOrderSession::KEY => [[
            'order_id' => $order->id, 'store_id' => $r->id, 'placed_at' => now()->toIso8601String(),
        ]]])->postJson(route('customer.register', $r), [
            'name' => 'Guest', 'email' => 'guest@test.com',
            'password' => 'Password123!', 'password_confirmation' => 'Password123!',
            'store_id' => $r->id,
        ])->assertOk()->assertJson(['success' => true, 'claimed_count' => 1]);

        // The account panel (JSON) now lists that formerly-guest order.
        $this->getJson(route('customer.account', $r))
            ->assertOk()
            ->assertJsonPath('orders.0.id', $order->id);
    }
}
