<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Modules\Customers\Mail\CustomerClaimVerify;
use App\Models\Customer;
use App\Models\Order;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use App\Services\GuestOrderSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GOL A3 (rev/guest-order-linking): register() is now three-way on an email
 * match. Covers all four outcomes + the attacker case + the 409-lockout fix.
 */
class CustomerRegisterClaimTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
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

    private function payload(Store $r, array $extra = []): array
    {
        return array_merge([
            'name' => 'New Guest',
            'email' => 'guest@test.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'store_id' => $r->id,
        ], $extra);
    }

    private function sessionWithStamp(Order $order): array
    {
        return [GuestOrderSession::KEY => [[
            'order_id' => $order->id, 'store_id' => $order->store_id, 'placed_at' => now()->toIso8601String(),
        ]]];
    }

    /** (a) A passworded account already owns the email → 409, unchanged. */
    public function test_real_account_email_returns_409(): void
    {
        $r = $this->makeRestaurant();
        Customer::create(['store_id' => $r->id, 'name' => 'Real', 'email' => 'guest@test.com', 'password' => bcrypt('secret-123')]);

        $this->postJson(route('customer.register', $r), $this->payload($r))
            ->assertStatus(409);
        $this->assertFalse(Auth::guard('customer')->check());
    }

    /** (b) Shadow + same-session proof → adopt in place, log in, claim orders. */
    public function test_shadow_with_session_proof_is_adopted_and_orders_claimed(): void
    {
        $r = $this->makeRestaurant();
        $shadow = Customer::create(['store_id' => $r->id, 'name' => 'Guest', 'email' => 'guest@test.com', 'source' => 'checkout']);
        $order = Order::create(['store_id' => $r->id, 'customer_id' => $shadow->id, 'customer_name' => 'Guest']);

        $this->withSession($this->sessionWithStamp($order))
            ->postJson(route('customer.register', $r), $this->payload($r))
            ->assertOk()
            ->assertJson(['success' => true, 'claimed_count' => 1]);

        // Same row adopted (id preserved → order still attached), password now set.
        $shadow->refresh();
        $this->assertNotEmpty($shadow->password);
        $this->assertTrue(Hash::check('Password123!', $shadow->password));
        $this->assertSame($shadow->id, $order->fresh()->customer_id);
        $this->assertTrue(Auth::guard('customer')->check());
        // Exactly one customer row for this email (no duplicate created).
        $this->assertSame(1, Customer::where('store_id', $r->id)->where('email', 'guest@test.com')->count());
    }

    /** (c) Shadow, NO session proof → pending verification, not logged in, email sent. */
    public function test_shadow_without_proof_sends_verification_and_does_not_log_in(): void
    {
        $r = $this->makeRestaurant();
        $shadow = Customer::create(['store_id' => $r->id, 'name' => 'Guest', 'email' => 'guest@test.com', 'source' => 'checkout']);
        Order::create(['store_id' => $r->id, 'customer_id' => $shadow->id, 'customer_name' => 'Guest']);

        $this->postJson(route('customer.register', $r), $this->payload($r))
            ->assertOk()
            ->assertJson(['success' => true, 'pending_verification' => true]);

        $shadow->refresh();
        $this->assertEmpty($shadow->password);              // NOT activated
        $this->assertNotEmpty($shadow->pending_password);   // parked
        $this->assertFalse(Auth::guard('customer')->check());
        Mail::assertQueued(CustomerClaimVerify::class);
    }

    /** No match → create + log in + claim a phone-only same-session guest order. */
    public function test_fresh_email_creates_account_and_claims_session_order(): void
    {
        $r = $this->makeRestaurant();
        $order = Order::create(['store_id' => $r->id, 'customer_name' => 'Guest']); // unowned

        $this->withSession($this->sessionWithStamp($order))
            ->postJson(route('customer.register', $r), $this->payload($r, ['email' => 'fresh@test.com']))
            ->assertOk()
            ->assertJson(['success' => true, 'claimed_count' => 1]);

        $me = Customer::where('store_id', $r->id)->where('email', 'fresh@test.com')->firstOrFail();
        $this->assertSame($me->id, $order->fresh()->customer_id);
        $this->assertTrue(Auth::guard('customer')->check());
    }

    /**
     * ATTACKER: registering a stranger's shadow email from a CLEAN session must
     * not log in, must not activate the shadow, must not touch its orders — it
     * only mails the real owner a verification link.
     */
    public function test_attacker_cannot_hijack_shadow_from_clean_session(): void
    {
        $r = $this->makeRestaurant();
        $shadow = Customer::create(['store_id' => $r->id, 'name' => 'Victim', 'email' => 'victim@test.com', 'source' => 'checkout']);
        $victimOrder = Order::create(['store_id' => $r->id, 'customer_id' => $shadow->id, 'customer_name' => 'Victim']);

        $this->postJson(route('customer.register', $r), $this->payload($r, ['email' => 'victim@test.com']))
            ->assertOk()
            ->assertJson(['pending_verification' => true]);

        $this->assertFalse(Auth::guard('customer')->check());
        $this->assertEmpty($shadow->fresh()->password);
        $this->assertSame($shadow->id, $victimOrder->fresh()->customer_id); // untouched
    }

    /**
     * LOCKOUT REGRESSION: guest checkout made a shadow for this email; the same
     * browser registering that email now SUCCEEDS (pre-fix this 409'd, locking
     * the user out of their own email).
     */
    public function test_guest_then_register_same_email_no_longer_409s(): void
    {
        $r = $this->makeRestaurant();
        $shadow = Customer::create(['store_id' => $r->id, 'name' => 'Guest', 'email' => 'guest@test.com', 'source' => 'checkout']);
        $order = Order::create(['store_id' => $r->id, 'customer_id' => $shadow->id, 'customer_name' => 'Guest']);

        $this->withSession($this->sessionWithStamp($order))
            ->postJson(route('customer.register', $r), $this->payload($r))
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    /* ------------------------------------------------------------------
       GOL A3b — the (store_id, phone) unique key. Every case above
       sends NO phone, so the second collision axis went untested and a
       fresh email + known phone 500'd on the insert in production.
       ------------------------------------------------------------------ */

    /** Fresh email, phone owned by a REAL account → 409, never a 500. */
    public function test_fresh_email_with_phone_of_real_account_returns_409_not_500(): void
    {
        $r = $this->makeRestaurant();
        Customer::create(['store_id' => $r->id, 'name' => 'Real', 'email' => 'owner@test.com', 'phone' => '9647505865364', 'password' => bcrypt('secret-123')]);

        $this->postJson(route('customer.register', $r), $this->payload($r, ['email' => 'fresh@test.com', 'phone' => '9647505865364']))
            ->assertStatus(409);

        $this->assertFalse(Auth::guard('customer')->check());
        $this->assertNull(Customer::where('store_id', $r->id)->where('email', 'fresh@test.com')->first());
    }

    /** Fresh email, phone of an UNPROVEN shadow → 409, shadow untouched. */
    public function test_fresh_email_with_phone_of_unproven_shadow_returns_409(): void
    {
        $r = $this->makeRestaurant();
        $shadow = Customer::create(['store_id' => $r->id, 'name' => 'Victim', 'email' => 'victim@test.com', 'phone' => '0944000444', 'source' => 'checkout']);
        $victimOrder = Order::create(['store_id' => $r->id, 'customer_id' => $shadow->id, 'customer_name' => 'Victim']);

        $this->postJson(route('customer.register', $r), $this->payload($r, ['email' => 'fresh@test.com', 'phone' => '0944000444']))
            ->assertStatus(409);

        $this->assertFalse(Auth::guard('customer')->check());
        $shadow->refresh();
        $this->assertEmpty($shadow->password);                          // not activated
        $this->assertSame('victim@test.com', $shadow->email);           // email not stolen
        $this->assertSame($shadow->id, $victimOrder->fresh()->customer_id);
    }

    /** Fresh email, phone of a shadow this browser PROVED → adopt in place. */
    public function test_fresh_email_with_proven_phone_shadow_is_adopted(): void
    {
        $r = $this->makeRestaurant();
        $shadow = Customer::create(['store_id' => $r->id, 'name' => 'Guest', 'phone' => '0944000444', 'source' => 'checkout']);
        $order = Order::create(['store_id' => $r->id, 'customer_id' => $shadow->id, 'customer_name' => 'Guest']);

        $this->withSession($this->sessionWithStamp($order))
            ->postJson(route('customer.register', $r), $this->payload($r, ['email' => 'fresh@test.com', 'phone' => '0944000444']))
            ->assertOk()
            ->assertJson(['success' => true, 'claimed_count' => 1]);

        // Same row reused — id preserved, order still attached, no duplicate.
        $shadow->refresh();
        $this->assertSame('fresh@test.com', $shadow->email);
        $this->assertTrue(Hash::check('Password123!', $shadow->password));
        $this->assertSame($shadow->id, $order->fresh()->customer_id);
        $this->assertSame(1, Customer::where('store_id', $r->id)->where('phone', '0944000444')->count());
        $this->assertTrue(Auth::guard('customer')->check());
    }

    /** Fresh email + fresh phone still registers normally. */
    public function test_fresh_email_and_fresh_phone_still_registers(): void
    {
        $r = $this->makeRestaurant();
        Customer::create(['store_id' => $r->id, 'name' => 'Other', 'email' => 'other@test.com', 'phone' => '1111111111', 'password' => bcrypt('secret-123')]);

        $this->postJson(route('customer.register', $r), $this->payload($r, ['email' => 'fresh@test.com', 'phone' => '2222222222']))
            ->assertOk()
            ->assertJson(['success' => true]);

        $me = Customer::where('store_id', $r->id)->where('email', 'fresh@test.com')->firstOrFail();
        $this->assertSame('2222222222', $me->phone);
        $this->assertTrue(Auth::guard('customer')->check());
    }

    /**
     * The email three-way still wins when BOTH keys match the same row — a
     * returning guest with their own email and phone must not fall into the
     * phone branch and 409.
     */
    public function test_same_row_matching_both_email_and_phone_takes_email_path(): void
    {
        $r = $this->makeRestaurant();
        $shadow = Customer::create(['store_id' => $r->id, 'name' => 'Guest', 'email' => 'guest@test.com', 'phone' => '3333333333', 'source' => 'checkout']);
        $order = Order::create(['store_id' => $r->id, 'customer_id' => $shadow->id, 'customer_name' => 'Guest']);

        $this->withSession($this->sessionWithStamp($order))
            ->postJson(route('customer.register', $r), $this->payload($r, ['phone' => '3333333333']))
            ->assertOk()
            ->assertJson(['success' => true, 'claimed_count' => 1]);

        $this->assertSame(1, Customer::where('store_id', $r->id)->count());
        $this->assertTrue(Auth::guard('customer')->check());
    }
}
