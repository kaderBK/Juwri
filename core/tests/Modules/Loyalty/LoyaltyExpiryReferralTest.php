<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyLedger;
use App\Models\Order;
use App\Models\StoreOption;
use App\Services\Loyalty\LoyaltyAccrual;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Marketing\Http\Middleware\CaptureGuestReferral;
use Tests\TestCase;

/**
 * HPMS E4 — loyalty points expiry + guest refer-a-friend.
 */
class LoyaltyExpiryReferralTest extends TestCase
{
    use RefreshDatabase;

    private function store(): Store
    {
        $owner = User::factory()->create(['status' => 1]);
        $title = 'Store '.Str::random(6);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'x.png', 'cover_image' => 'x.png', 'address' => 'a', 'phone' => '1',
        ]);
    }

    private function customer(Store $store, array $attrs = []): Customer
    {
        return Customer::create($attrs + ['store_id' => $store->id, 'name' => 'C'.Str::random(4)]);
    }

    // ---- referral code mint ---------------------------------------------------

    public function test_referral_code_is_minted_lazily_and_stable(): void
    {
        $store = $this->store();
        $c = $this->customer($store);
        $this->assertNull($c->referral_code);

        $code = $c->referralCode();
        $this->assertNotEmpty($code);
        $this->assertSame($code, $c->fresh()->referral_code, 'persisted');
        $this->assertSame($code, $c->referralCode(), 'stable on re-read');
    }

    // ---- expiry ---------------------------------------------------------------

    public function test_expire_stale_expires_old_points_and_is_idempotent(): void
    {
        $store = $this->store();
        $c = $this->customer($store);
        $account = LoyaltyAccount::forCustomer((int) $c->id, (int) $store->id);

        $account->earn(9001, 100, 'old');   // 100 old points
        $account->earn(9002, 50, 'recent'); // 50 recent points
        // Backdate only the "old" earn beyond the window.
        LoyaltyLedger::where('loyalty_account_id', $account->id)->where('note', 'old')
            ->update(['created_at' => now()->subDays(400)]);

        $expired = $account->fresh()->expireStale(365);
        $this->assertSame(100, $expired);
        $this->assertSame(50, (int) $account->fresh()->balance);

        // Idempotent — a second run finds nothing new to expire.
        $this->assertSame(0, $account->fresh()->expireStale(365));
        $this->assertSame(50, (int) $account->fresh()->balance);
    }

    public function test_expire_stale_window_zero_is_a_noop(): void
    {
        $store = $this->store();
        $c = $this->customer($store);
        $account = LoyaltyAccount::forCustomer((int) $c->id, (int) $store->id);
        $account->earn(9101, 40, 'x');
        LoyaltyLedger::where('loyalty_account_id', $account->id)->update(['created_at' => now()->subDays(999)]);

        $this->assertSame(0, $account->fresh()->expireStale(0));
        $this->assertSame(40, (int) $account->fresh()->balance);
    }

    public function test_expire_points_command_uses_store_window(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'loyalty_points_expiry_days', '30');
        $c = $this->customer($store);
        $account = LoyaltyAccount::forCustomer((int) $c->id, (int) $store->id);
        $account->earn(9201, 70, 'aged');
        LoyaltyLedger::where('loyalty_account_id', $account->id)->update(['created_at' => now()->subDays(60)]);

        $this->artisan('loyalty:expire-points')->assertExitCode(0);
        $this->assertSame(0, (int) $account->fresh()->balance);
    }

    // ---- referrals ------------------------------------------------------------

    private function paidOrder(Store $store, Customer $referee): Order
    {
        return Order::factory()->create(['store_id' => $store->id, 'customer_id' => $referee->id, 'is_paid' => 1]);
    }

    public function test_referral_credits_both_sides_once(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'loyalty_referral_points_referrer', '10');
        StoreOption::updateStoreOption($store->id, 'loyalty_referral_points_referee', '5');

        $referrer = $this->customer($store, ['referral_code' => 'ABC12345']);
        $referee = $this->customer($store, ['referred_by_code' => 'ABC12345']);

        LoyaltyAccrual::earnForOrder($this->paidOrder($store, $referee));

        $this->assertSame(10, (int) LoyaltyAccount::forCustomer((int) $referrer->id, (int) $store->id)->balance);
        $this->assertSame(5, (int) LoyaltyAccount::forCustomer((int) $referee->id, (int) $store->id)->balance);

        // A second paid order does not double-credit.
        LoyaltyAccrual::earnForOrder($this->paidOrder($store, $referee));
        $this->assertSame(10, (int) LoyaltyAccount::forCustomer((int) $referrer->id, (int) $store->id)->balance);
        $this->assertSame(5, (int) LoyaltyAccount::forCustomer((int) $referee->id, (int) $store->id)->balance);
    }

    public function test_self_referral_is_blocked(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'loyalty_referral_points_referrer', '10');
        StoreOption::updateStoreOption($store->id, 'loyalty_referral_points_referee', '5');

        $me = $this->customer($store, ['referral_code' => 'SELF9999', 'referred_by_code' => 'SELF9999']);
        LoyaltyAccrual::earnForOrder($this->paidOrder($store, $me));

        $this->assertSame(0, (int) LoyaltyAccount::forCustomer((int) $me->id, (int) $store->id)->balance);
    }

    public function test_referral_is_dark_when_points_are_zero(): void
    {
        $store = $this->store(); // no referral point options set → 0
        $referrer = $this->customer($store, ['referral_code' => 'DARK0000']);
        $referee = $this->customer($store, ['referred_by_code' => 'DARK0000']);

        LoyaltyAccrual::earnForOrder($this->paidOrder($store, $referee));
        $this->assertSame(0, (int) LoyaltyAccount::forCustomer((int) $referrer->id, (int) $store->id)->balance);
    }

    public function test_referral_not_credited_on_unpaid_order(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'loyalty_referral_points_referrer', '10');
        $referrer = $this->customer($store, ['referral_code' => 'UNPD1111']);
        $referee = $this->customer($store, ['referred_by_code' => 'UNPD1111']);

        $order = Order::factory()->create(['store_id' => $store->id, 'customer_id' => $referee->id, 'is_paid' => 0]);
        LoyaltyAccrual::earnForOrder($order);
        $this->assertSame(0, (int) LoyaltyAccount::forCustomer((int) $referrer->id, (int) $store->id)->balance);
    }

    // ---- visit capture middleware --------------------------------------------

    public function test_capture_middleware_stashes_ref_cookie(): void
    {
        // P1-23: a lasting attribution cookie needs the visitor's marketing consent (CookieConsent).
        $mw = new CaptureGuestReferral;
        $mw->handle(Request::create('/some-store?ref=ABC12345', 'GET', [], ['site_consent' => 'v1.a0.m1']), fn ($r) => response('ok'));

        $queued = Cookie::getQueuedCookies();
        $names = array_map(fn ($c) => $c->getName(), $queued);
        $this->assertContains('mb_ref', $names);
        $cookie = collect($queued)->first(fn ($c) => $c->getName() === 'mb_ref');
        $this->assertSame('ABC12345', $cookie->getValue());
    }
}
