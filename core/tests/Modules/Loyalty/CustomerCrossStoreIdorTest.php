<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyProgram;
use App\Models\Order;
use App\Services\CustomerWalletService;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\EntitlementWriter;
use Juwri\Modules\Ordering\Models\CustomerNotification;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-178: IDOR proofs for the storefront customer routes the P6-59 sweep left
 * "unresolved" (IDOR-SWEEP.md). A signed-in customer of store A aims every
 * {storeId} route at store B, where victim customer C has points, store
 * credit, a notification and an order. Nothing of C's may be read, spent,
 * marked read or topped up. Positive controls prove C reaches the same routes,
 * so the 404s are not vacuous (feature gate off, missing program, ...).
 */
class CustomerCrossStoreIdorTest extends TestCase
{
    use RefreshDatabase;

    private Store $storeA;

    private Store $storeB;

    private Customer $attacker;

    private Customer $victim;

    private CustomerNotification $victimNote;

    private Order $victimOrder;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        config(['settings.admin_allow_loyalty' => true, 'settings.admin_allow_customer_wallet' => true]);

        $this->storeA = $this->store();
        $this->storeB = $this->store();
        $this->attacker = Customer::create(['store_id' => $this->storeA->id, 'name' => 'Mallory', 'phone' => '55-'.rand(1000, 9999)]);
        $this->victim = Customer::create(['store_id' => $this->storeB->id, 'name' => 'Victim', 'phone' => '66-'.rand(1000, 9999)]);

        LoyaltyAccount::forCustomer($this->victim->id, $this->storeB->id)->earn(990001, 50, 'seed');
        CustomerWalletService::credit($this->victim, 40, 'seed', 'seed:'.$this->victim->id);

        $order = new Order;
        $order->forceFill([
            'store_id' => $this->storeB->id, 'customer_id' => $this->victim->id, 'type' => 'takeaway',
            'status' => 'completed', 'is_paid' => 1, 'source' => 'qr', 'track_token' => Str::random(32), 'created_at' => now(),
        ])->save();
        $this->victimOrder = $order;
        $this->victimNote = CustomerNotification::create([
            'customer_id' => $this->victim->id, 'store_id' => $this->storeB->id, 'order_id' => $order->id,
            'type' => 'order_status', 'title' => 'VICTIM-MARKER', 'body' => 'secret',
        ]);

        FeatureRegistry::clearCache();
        FeatureAccess::flush();
    }

    private function store(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999, 'allow_ordering' => 1]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'idor-'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
        ]);
        LoyaltyProgram::create([
            'store_id' => $store->id, 'type' => 'points', 'earn_rate' => 1, 'reward_threshold' => 5,
            'reward_description' => 'Free side', 'reward_discount' => 5, 'active' => true,
        ]);
        EntitlementWriter::grant($owner, 'loyalty', 'admin', null, '1', null, 'test');

        return $store;
    }

    private function victimPoints(): int
    {
        return (int) LoyaltyAccount::forCustomer($this->victim->id, $this->storeB->id)->fresh()->balance;
    }

    public function test_positive_controls_the_victim_reaches_their_own_store(): void
    {
        $this->actingAs($this->victim, 'customer')->getJson(route('publicView.loyalty.balance', $this->storeB->id))
            ->assertOk()->assertJsonPath('active', true)->assertJsonPath('balance', 50);
        $this->actingAs($this->victim, 'customer')->getJson(route('publicView.wallet.balance', $this->storeB->id))->assertOk();
        $this->actingAs($this->victim, 'customer')->getJson(route('publicView.notifications.index', $this->storeB->id))
            ->assertOk()->assertSee('VICTIM-MARKER');
        $this->actingAs($this->victim, 'customer')->getJson(route('publicView.myOrders.index', $this->storeB->id))
            ->assertOk()->assertJsonPath('orders.0.id', (int) $this->victimOrder->id);
    }

    public function test_loyalty_balance_redeem_and_wallet_pass_of_another_store_are_404(): void
    {
        $as = $this->actingAs($this->attacker, 'customer');

        $as->getJson(route('publicView.loyalty.balance', $this->storeB->id))->assertNotFound()->assertDontSee('"balance"', false);
        $as->postJson(route('publicView.loyalty.redeem', $this->storeB->id))->assertNotFound();
        $as->getJson(route('publicView.googleWallet.loyaltyJwt', $this->storeB->id))->assertNotFound()->assertJsonMissingPath('save_url');

        $this->assertSame(50, $this->victimPoints());
        // The attacker gets no loyalty account opened in store B either.
        $this->assertSame(0, LoyaltyAccount::where('store_id', $this->storeB->id)->where('customer_id', $this->attacker->id)->count());
    }

    public function test_wallet_balance_topup_page_and_topup_start_of_another_store_are_404(): void
    {
        $as = $this->actingAs($this->attacker, 'customer');
        $before = Transaction::count();

        $as->getJson(route('publicView.wallet.balance', $this->storeB->id))->assertNotFound()->assertJsonMissingPath('balance');
        $as->get(route('publicView.wallet.topup', $this->storeB->id))->assertNotFound();
        $as->post(route('publicView.wallet.topup.start', $this->storeB->id), ['amount' => 10, 'payment_method' => 'stripe'])->assertNotFound();

        $this->assertSame($before, Transaction::count(), 'no top-up transaction opened in store B');
        $this->assertEqualsWithDelta(40.0, CustomerWalletService::balanceFor($this->victim->fresh()), 0.000001);
    }

    public function test_notifications_are_the_callers_own_whatever_store_id_is_in_the_url(): void
    {
        $as = $this->actingAs($this->attacker, 'customer');

        $as->getJson(route('publicView.notifications.index', $this->storeB->id))
            ->assertOk()->assertJsonPath('unread', 0)->assertJsonCount(0, 'notifications')->assertDontSee('VICTIM-MARKER');

        $as->postJson(route('publicView.notifications.read', $this->storeB->id), ['ids' => [$this->victimNote->id]])->assertOk();
        $this->assertNull($this->victimNote->fresh()->read_at, "the victim's notification stays unread");
    }

    public function test_my_orders_and_refund_request_do_not_reach_another_customers_orders(): void
    {
        $as = $this->actingAs($this->attacker, 'customer');

        $as->getJson(route('publicView.myOrders.index', $this->storeB->id))->assertOk()->assertJsonCount(0, 'orders');
        $as->postJson(route('publicView.myOrders.refundRequest', [$this->storeB->id, $this->victimOrder->id]), ['reason' => 'x'])
            ->assertNotFound();
        $this->assertSame('completed', $this->victimOrder->fresh()->status);
    }
}
