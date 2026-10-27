<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use App\Models\StoreTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Messaging\Models\SmsLog;
use Juwri\Modules\Messaging\Services\SmsOtpService;
use Juwri\Modules\Messaging\Services\SmsService;
use Juwri\Modules\Ordering\Http\Controllers\CheckoutOtpController;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * F7 · OTP checkout gate. Wiring over the existing SmsOtpService — no real SMS
 * is ever sent (the test env "simulates" the send and logs the code preview).
 */
class CheckoutOtpGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        // Order placement dispatches confirmation mail/notifications + jobs; fake
        // them so the empty test-store email template never hits the CSS inliner.
        Mail::fake();
        Notification::fake();
        Queue::fake();

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    // --- helpers ---------------------------------------------------------

    private function makeRestaurant(bool $requireVerification = false): Store
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000, 'settings' => ['restaurant_limit' => 5, 'allow_ordering' => '1'], 'status' => 1]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);

        if ($requireVerification) {
            StoreOption::updateStoreOption($store->id, 'require_phone_verification', '1');
        }

        return $store;
    }

    private function makeMenuItem(Store $store): Menu
    {
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat']);

        return Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Item', 'price' => 10.00, 'active' => 1]);
    }

    private function submitOrder(Store $store, Menu $item, array $extra = []): TestResponse
    {
        return $this->post(route('store.submitOrder', $store), array_merge([
            'items' => json_encode([['id' => $item->id, 'quantity' => 1, 'variants' => 0, 'extras' => [], 'mods' => []]]),
            'name' => 'Test Guest',
            'ordering-type' => 'takeaway',
            'phone-number' => '+16505551234',
            'pay_via' => 'cash',
            'total' => '10.00',
        ], $extra));
    }

    /**
     * Trigger a real OTP send through the checkout endpoint (which records the
     * store the code belongs to in the session) and pull the code out of the
     * simulated SMS log.
     */
    private function issueCode(string $phone, ?int $storeId = null): string
    {
        $this->post(route('checkout.otp.send'), array_filter(['phone' => $phone, 'store_id' => $storeId]))
            ->assertJson(['success' => true]);
        $preview = SmsLog::where('feature', 'otp')->latest('id')->value('message_preview');
        preg_match('/(\d{4,6})/', (string) $preview, $m);

        return $m[1] ?? '';
    }

    // --- gate on/off -----------------------------------------------------

    public function test_gate_off_lets_unverified_order_through(): void
    {
        $store = $this->makeRestaurant(false);
        $item = $this->makeMenuItem($store);

        $res = $this->submitOrder($store, $item);

        $res->assertJson(['success' => true]);
        $this->assertDatabaseHas('orders', ['store_id' => $store->id]);
    }

    public function test_gate_on_blocks_unverified_order_with_otp_required(): void
    {
        $store = $this->makeRestaurant(true);
        $item = $this->makeMenuItem($store);

        $res = $this->submitOrder($store, $item);

        $res->assertJson(['success' => false, 'otp_required' => true]);
        $this->assertDatabaseMissing('orders', ['store_id' => $store->id]);
    }

    public function test_on_table_order_skips_the_gate(): void
    {
        $store = $this->makeRestaurant(true);
        $item = $this->makeMenuItem($store);
        StoreTable::firstOrCreate(
            ['store_id' => $store->id, 'table_no' => 5],
            ['key' => Str::random(10)]
        );

        $res = $this->submitOrder($store, $item, [
            'ordering-type' => 'on-table',
            'table' => '5',
            'phone-number' => '',
        ]);

        $res->assertJson(['success' => true]);
        $this->assertDatabaseHas('orders', ['store_id' => $store->id]);
    }

    // --- send + verify happy path ---------------------------------------

    public function test_send_and_verify_then_order_succeeds(): void
    {
        $store = $this->makeRestaurant(true);
        $item = $this->makeMenuItem($store);
        $phone = '+16505551234';

        // send
        $send = $this->post(route('checkout.otp.send'), ['phone' => $phone, 'store_id' => $store->id]);
        $send->assertJson(['success' => true]);

        // grab the code from the simulated SMS log and verify
        $preview = SmsLog::where('feature', 'otp')->latest('id')->value('message_preview');
        preg_match('/(\d{4,6})/', (string) $preview, $m);
        $code = $m[1];

        $verify = $this->post(route('checkout.otp.verify'), ['phone' => $phone, 'code' => $code]);
        $verify->assertJson(['success' => true]);

        // now the order goes through (session marker set)
        $res = $this->submitOrder($store, $item);
        $res->assertJson(['success' => true]);
        $this->assertDatabaseHas('orders', ['store_id' => $store->id]);
    }

    public function test_verify_sets_customer_phone_verified_at(): void
    {
        $store = $this->makeRestaurant(true);
        $phone = '+16505551234';
        $customer = Customer::create(['store_id' => $store->id, 'name' => 'Jane', 'phone' => $phone]);
        $this->assertNull($customer->phone_verified_at);

        $code = $this->issueCode($phone, $store->id);

        $this->actingAs($customer, 'customer')
            ->post(route('checkout.otp.verify'), ['phone' => $phone, 'code' => $code])
            ->assertJson(['success' => true]);

        $this->assertNotNull($customer->fresh()->phone_verified_at);
    }

    public function test_already_verified_customer_skips_gate(): void
    {
        $store = $this->makeRestaurant(true);
        $item = $this->makeMenuItem($store);
        $phone = '+16505551234';
        $customer = Customer::create(['store_id' => $store->id, 'name' => 'Jane', 'phone' => $phone, 'phone_verified_at' => now()]);

        $res = $this->actingAs($customer, 'customer')->submitOrder($store, $item);

        $res->assertJson(['success' => true]);
        $this->assertDatabaseHas('orders', ['store_id' => $store->id]);
    }

    // --- wrong / expired code -------------------------------------------

    public function test_wrong_code_blocks_verification(): void
    {
        $store = $this->makeRestaurant(true);
        $phone = '+16505551234';
        $this->issueCode($phone, $store->id);

        $res = $this->post(route('checkout.otp.verify'), ['phone' => $phone, 'code' => '000000']);

        $res->assertStatus(422)->assertJson(['success' => false]);
    }

    public function test_expired_code_blocks_verification(): void
    {
        $store = $this->makeRestaurant(true);
        $phone = '+16505551234';
        $code = $this->issueCode($phone, $store->id);

        // Simulate expiry: the SmsOtpService cache entry is gone after TTL.
        Cache::forget(app(SmsOtpService::class)->key(
            CheckoutOtpController::scope((int) $store->id),
            app(SmsService::class)->normalizePhone($phone)
        ));

        $res = $this->post(route('checkout.otp.verify'), ['phone' => $phone, 'code' => $code]);

        $res->assertStatus(422)->assertJson(['success' => false]);
    }

    // --- resend cooldown / attempt cap ----------------------------------

    public function test_resend_cooldown_is_enforced(): void
    {
        $store = $this->makeRestaurant(true);
        $phone = '+16505559999';

        // SmsOtpService allows 3 requests per phone per window; the 4th is capped.
        $this->post(route('checkout.otp.send'), ['phone' => $phone, 'store_id' => $store->id])->assertJson(['success' => true]);
        $this->post(route('checkout.otp.send'), ['phone' => $phone, 'store_id' => $store->id])->assertJson(['success' => true]);
        $this->post(route('checkout.otp.send'), ['phone' => $phone, 'store_id' => $store->id])->assertJson(['success' => true]);

        $capped = $this->post(route('checkout.otp.send'), ['phone' => $phone, 'store_id' => $store->id]);
        $capped->assertStatus(429)->assertJson(['success' => false]);
    }

    public function test_attempt_cap_invalidates_the_code(): void
    {
        $store = $this->makeRestaurant(true);
        $phone = '+16505558888';
        $code = $this->issueCode($phone, $store->id);

        // 5 wrong attempts exhaust the cap inside SmsOtpService.
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('checkout.otp.verify'), ['phone' => $phone, 'code' => '111111'])
                ->assertStatus(422);
        }

        // The correct code no longer works — cap hit, code invalidated.
        $this->post(route('checkout.otp.verify'), ['phone' => $phone, 'code' => $code])
            ->assertStatus(422)->assertJson(['success' => false]);
    }
}
