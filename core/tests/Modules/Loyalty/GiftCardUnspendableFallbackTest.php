<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\GiftCard;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StoreOption;
use App\Services\CustomerWalletService;
use App\Services\GiftCardService;
use App\Services\OrderApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Payments\Contracts\Gateways;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\Support\TestPlans;
use Tests\Support\UniquePhone;
use Tests\TestCase;

class GiftCardUnspendableFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware();
    }

    /** @return array{owner: User, r: Store, menu: Menu} */
    private function make(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1']),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($r->id, 'gift_cards_enabled', '1');
        StoreOption::updateStoreOption($r->id, 'currency_code', 'USD');
        StoreOption::updateStoreOption($r->id, 'store_submit_order_notification', 0);
        StoreOption::updateStoreOption($r->id, 'quickorder_enable', 0);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C', 'parent' => null, 'position' => 1]);
        $menu = Menu::create([
            'store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Item', 'description' => 'd',
            'price' => 10, 'image' => 'default.png', 'type' => 'veg', 'active' => '1', 'position' => 1,
        ]);

        return compact('owner', 'r', 'menu');
    }

    /**
     * Fund + activate a card through the given gateway folder; optionally settle its funding Transaction.
     *
     * @return array{card: GiftCard, transaction: Transaction}
     */
    private function card(Store $r, float $value, string $gateway = 'stripe', ?string $status = null): array
    {
        ['card' => $card, 'transaction' => $txn] = GiftCardService::initiate($r->id, $value, $gateway);
        if ($status !== null) {
            $txn->forceFill(['status' => $status])->save();
        }
        GiftCardService::activate($card, $txn);

        return ['card' => $card->fresh(), 'transaction' => $txn->fresh()];
    }

    private function customer(Store $r): Customer
    {
        return Customer::create(['name' => 'C', 'store_id' => $r->id, 'phone' => UniquePhone::next('55-')]);
    }

    private function order(User $owner, Store $r, Menu $menu, int $qty, ?string $code = null, ?Customer $customer = null): Order
    {
        return OrderApiService::create($owner->id, $r->id, [
            'type' => 'takeaway', 'source' => 'api',
            'gift_card_code' => $code,
            'customer_id' => $customer?->id,
            'items' => [['item_id' => $menu->id, 'quantity' => $qty]],
        ]);
    }

    public function test_expired_card_without_a_provider_payment_refunds_to_store_credit(): void
    {
        ['owner' => $o, 'r' => $r, 'menu' => $m] = $this->make();
        config(['settings.admin_allow_customer_wallet' => true]);
        ['card' => $card] = $this->card($r, 50, 'cash');
        $customer = $this->customer($r);
        $order = $this->order($o, $r, $m, 2, $card->code, $customer)->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $order->gift_card_applied, 0.001);

        $card->forceFill(['expires_at' => now()->subDay()])->save();

        $this->mock(Gateways::class, fn ($m) => $m->shouldReceive('refundStorePayment')->never());

        $result = GiftCardService::refundUnspendable((int) $order->id, (int) $card->id, (float) $order->gift_card_applied);

        $this->assertSame('refunded_to_store_credit', $result['route']);
        $this->assertTrue(ActivityLog::where('action', 'giftcard.refunded_to_store_credit')->where('store_id', $r->id)->exists());
        $this->assertFalse(ActivityLog::where('action', 'giftcard.refund_due')->exists());
        $this->assertEqualsWithDelta(20.0, CustomerWalletService::balanceFor($customer), 0.001);
    }

    public function test_expired_card_without_a_customer_is_refund_due_and_card_balance_is_unchanged(): void
    {
        ['owner' => $o, 'r' => $r, 'menu' => $m] = $this->make();
        ['card' => $card] = $this->card($r, 50, 'cash');
        $order = $this->order($o, $r, $m, 2, $card->code)->refresh();
        $this->assertEqualsWithDelta(20.0, (float) $order->gift_card_applied, 0.001);
        $balanceBefore = $card->fresh()->balance();

        $card->forceFill(['expires_at' => now()->subDay()])->save();

        $this->mock(Gateways::class, fn ($m) => $m->shouldReceive('refundStorePayment')->never());

        $result = GiftCardService::refundUnspendable((int) $order->id, (int) $card->id, (float) $order->gift_card_applied);

        $this->assertSame('refund_due', $result['route']);
        $this->assertTrue(ActivityLog::where('action', 'giftcard.refund_due')->where('store_id', $r->id)->exists());
        $this->assertFalse(ActivityLog::where('action', 'giftcard.refunded_to_store_credit')->exists());
        $this->assertEqualsWithDelta($balanceBefore, $card->fresh()->balance(), 0.001);
    }

    public function test_a_manual_provider_result_is_refund_due_with_no_store_credit(): void
    {
        ['owner' => $o, 'r' => $r, 'menu' => $m] = $this->make();
        config(['settings.admin_allow_customer_wallet' => true]);
        ['card' => $card] = $this->card($r, 50, 'stripe', Transaction::STATUS_SUCCESS);
        $customer = $this->customer($r);
        $order = $this->order($o, $r, $m, 2, $card->code, $customer)->refresh();

        $card->forceFill(['expires_at' => now()->subDay()])->save();

        $this->mock(Gateways::class, fn ($m) => $m->shouldReceive('refundStorePayment')->once()
            ->andReturn(['automatic' => false, 'gateway' => 'stripe', 'message' => 'Recorded. Complete this refund in your Stripe dashboard.']));

        $result = GiftCardService::refundUnspendable((int) $order->id, (int) $card->id, (float) $order->gift_card_applied);

        $this->assertSame('refund_due', $result['route']);
        $this->assertTrue(ActivityLog::where('action', 'giftcard.refund_due')->where('store_id', $r->id)->exists());
        $this->assertFalse(ActivityLog::where('action', 'giftcard.refunded_to_store_credit')->exists());
        $this->assertEqualsWithDelta(0.0, CustomerWalletService::balanceFor($customer), 0.001);
    }

    public function test_a_fully_refunded_purchase_is_already_refunded_with_no_gateway_call(): void
    {
        ['owner' => $o, 'r' => $r, 'menu' => $m] = $this->make();
        config(['settings.admin_allow_customer_wallet' => true]);
        ['card' => $card, 'transaction' => $txn] = $this->card($r, 50, 'stripe', Transaction::STATUS_SUCCESS);
        $txn->forceFill(['details' => ['gateway_refunds' => [
            ['id' => 'ref-1', 'gateway' => 'stripe', 'amount_minor' => 5000, 'currency' => 'USD', 'status' => 'ok', 'reference' => 'ref-1', 'at' => now()->toIso8601String()],
        ]]])->save();
        $customer = $this->customer($r);
        $order = $this->order($o, $r, $m, 2, $card->code, $customer)->refresh();

        $card->forceFill(['expires_at' => now()->subDay()])->save();

        $this->mock(Gateways::class, fn ($m) => $m->shouldReceive('refundStorePayment')->never());

        $result = GiftCardService::refundUnspendable((int) $order->id, (int) $card->id, (float) $order->gift_card_applied);

        $this->assertSame('already_refunded', $result['route']);
        $this->assertTrue(ActivityLog::where('action', 'giftcard.already_refunded')->where('store_id', $r->id)->exists());
        $this->assertEqualsWithDelta(0.0, CustomerWalletService::balanceFor($customer), 0.001);
    }
}
