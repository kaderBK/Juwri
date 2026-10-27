<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

use App\Http\Middleware\Installed;
use App\Models\Customer;
use App\Models\Order;
use App\Services\GuestOrderSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Customers\Mail\CustomerClaimVerify;
use Juwri\Modules\Customers\Services\CustomerAccountClaimService;
use Tests\Support\TestPlans;
use Tests\Support\ThrowingMailTransport;
use Tests\TestCase;

/**
 * GOL A4 (rev/guest-order-linking): email-verified claim path — send throttle,
 * signed-link promotion + order claim, and the tamper/expiry rejections.
 */
class CustomerAccountClaimTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([Installed::class]);
    }

    private function makeRestaurant(): Store
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000, 'settings' => ['restaurant_limit' => 5, 'allow_ordering' => '1'], 'status' => 1]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);

        return Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function shadow(Store $r, string $email): Customer
    {
        return Customer::create(['store_id' => $r->id, 'name' => 'Guest', 'email' => $email, 'source' => 'checkout']);
    }

    public function test_send_parks_hash_emails_and_throttles(): void
    {
        Mail::fake();
        $r = $this->makeRestaurant();
        $shadow = $this->shadow($r, 'guest@test.com');
        $hash = Hash::make('chosen-pw-123');

        [$ok, $msg] = CustomerAccountClaimService::sendClaimEmail($shadow, $hash);
        $this->assertTrue($ok);
        $this->assertSame($hash, $shadow->fresh()->pending_password);
        Mail::assertQueued(CustomerClaimVerify::class);

        // 3 total allowed per hour; the 4th throttles.
        CustomerAccountClaimService::sendClaimEmail($shadow, $hash);
        CustomerAccountClaimService::sendClaimEmail($shadow, $hash);
        [$ok4] = CustomerAccountClaimService::sendClaimEmail($shadow, $hash);
        $this->assertFalse($ok4);
    }

    /** P6-192: a dead mail server is a plain message, never a 500, and does not use up the limit. */
    public function test_mail_failure_is_a_plain_message_and_does_not_spend_the_limit(): void
    {
        ThrowingMailTransport::install();
        Log::spy();
        $r = $this->makeRestaurant();
        $shadow = $this->shadow($r, 'dead-smtp@test.com');

        for ($i = 0; $i < 5; $i++) {
            [$ok, $msg] = CustomerAccountClaimService::sendClaimEmail($shadow, Hash::make('pw-123456'));
            $this->assertFalse($ok);
            $this->assertSame('We could not send the email. Please try again.', $msg, 'never the rate-limit message');
        }
        $key = 'customer-claim-email:dead-smtp@test.com:'.$r->id;
        $this->assertSame(0, RateLimiter::attempts($key));
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, '[customer-claim]'));

        // Mail works again: the first send goes out.
        Mail::fake();
        [$ok] = CustomerAccountClaimService::sendClaimEmail($shadow, Hash::make('pw-123456'));
        $this->assertTrue($ok);
        Mail::assertQueued(CustomerClaimVerify::class, fn ($m) => $m->afterCommit === true);
    }

    public function test_send_refuses_passworded_row(): void
    {
        Mail::fake();
        $r = $this->makeRestaurant();
        $real = Customer::create(['store_id' => $r->id, 'name' => 'Real', 'email' => 'real@test.com', 'password' => bcrypt('secret-123')]);

        [$ok] = CustomerAccountClaimService::sendClaimEmail($real, Hash::make('x'));
        $this->assertFalse($ok);
        Mail::assertNothingQueued();
    }

    public function test_valid_signed_link_promotes_logs_in_and_claims_orders(): void
    {
        $r = $this->makeRestaurant();
        $shadow = $this->shadow($r, 'guest@test.com');
        $hash = Hash::make('chosen-pw-123');
        $shadow->forceFill(['pending_password' => $hash])->save();

        $order = Order::create(['store_id' => $r->id, 'customer_name' => 'G']);

        $url = URL::temporarySignedRoute('customer.claim.verify', now()->addHours(48), [
            'store' => $r->id, 'customer' => $shadow->id,
        ]);

        // Stamp the order into the SAME session that follows the link.
        $this->withSession([GuestOrderSession::KEY => [[
            'order_id' => $order->id, 'store_id' => $r->id, 'placed_at' => now()->toIso8601String(),
        ]]])->get($url)->assertRedirect();

        $shadow->refresh();
        $this->assertSame($hash, $shadow->password);
        $this->assertNull($shadow->pending_password);
        $this->assertNotNull($shadow->email_verified_at);
        $this->assertSame($shadow->id, $order->fresh()->customer_id);
        $this->assertTrue(Auth::guard('customer')->check());
    }

    /** ATTACKER: a tampered/expired signature is rejected; nothing is promoted. */
    public function test_tampered_signature_is_rejected(): void
    {
        $r = $this->makeRestaurant();
        $shadow = $this->shadow($r, 'guest@test.com');
        $shadow->forceFill(['pending_password' => Hash::make('chosen-pw-123')])->save();

        $url = URL::temporarySignedRoute('customer.claim.verify', now()->addHours(48), [
            'store' => $r->id, 'customer' => $shadow->id,
        ]).'x'; // corrupt the signature

        $this->get($url)->assertStatus(403);

        $shadow->refresh();
        $this->assertEmpty($shadow->password);
        $this->assertNotNull($shadow->pending_password);
        $this->assertFalse(Auth::guard('customer')->check());
    }

    /** ATTACKER: a validly-signed link for a PASSWORDED account never re-sets a password. */
    public function test_signed_link_for_real_account_is_refused(): void
    {
        $r = $this->makeRestaurant();
        $real = Customer::create(['store_id' => $r->id, 'name' => 'Real', 'email' => 'real@test.com', 'password' => bcrypt('original-pw')]);

        $url = URL::temporarySignedRoute('customer.claim.verify', now()->addHours(48), [
            'store' => $r->id, 'customer' => $real->id,
        ]);

        $this->get($url)->assertRedirect();

        // Password unchanged, not logged in.
        $this->assertTrue(Hash::check('original-pw', $real->fresh()->password));
        $this->assertFalse(Auth::guard('customer')->check());
    }
}
