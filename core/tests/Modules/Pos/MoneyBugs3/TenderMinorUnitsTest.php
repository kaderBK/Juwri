<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos\MoneyBugs3;

use App\Models\Customer;
use App\Models\GiftCard;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StoreOption;
use App\Services\CustomerWalletService;
use App\Services\GiftCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Feature\MoneyBugs4\BeforeAfter;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * Bug batch 3 · C — store-credit and gift-card tenders are computed in integer minor units of the order currency
 * (no float floor: 0.29 stays 0.29), at the precision the ledger posts, and never exceed the balance or the amount due.
 */
class TenderMinorUnitsTest extends TestCase
{
    use BeforeAfter;
    use RefreshDatabase;

    private const D = 0.000001;

    /** @return array{0: Store, 1: Order, 2: Customer} an order of $qty × $price in $currency */
    private function order(string $currency, float $price, int $qty): array
    {
        $owner = User::factory()->create(['status' => 1]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'main_image' => 'a.png', 'cover_image' => 'a.png',
            'address' => 'x', 'phone' => '1',
        ]);
        StoreOption::updateStoreOption($r->id, 'currency_code', $currency);
        $menu = Menu::create(['store_id' => $r->id, 'name' => 'X', 'price' => $price, 'active' => '1']);
        $customer = Customer::create(['name' => 'C', 'store_id' => $r->id, 'phone' => UniquePhone::next('55-')]);
        $order = Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'pending', 'customer_id' => $customer->id]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => $qty, 'variation' => 0]);

        return [$r, $order->fresh(), $customer];
    }

    private function card(Store $r, float $value): GiftCard
    {
        return GiftCardService::issueManual($r->id, $value)->fresh();
    }

    public function test_usd_store_credit_of_029_applies_029(): void
    {
        [, $order, $customer] = $this->order('USD', 10, 3);           // 30.00
        CustomerWalletService::credit($customer, 0.29, 'seed', 'seed:'.$customer->id);

        $applied = CustomerWalletService::applyToOrder($customer, $order, 0.29);

        $this->assertEqualsWithDelta(0.29, $applied, self::D);                                          // before: 0.28
        $this->assertEqualsWithDelta(0.0, CustomerWalletService::balanceFor($customer->fresh()), self::D);  // before: 0.01 stranded
    }

    public function test_usd_gift_card_of_029_applies_029(): void
    {
        [$r, $order] = $this->order('USD', 10, 3);
        $card = $this->card($r, 0.29);

        $applied = GiftCardService::applyToOrder($card, $order);

        $this->assertEqualsWithDelta(0.29, $applied, self::D);                       // before: 0.28
        $this->assertEqualsWithDelta(0.0, $card->fresh()->balance(), self::D);       // before: 0.01 stranded
    }

    public function test_kwd_store_credit_never_exceeds_the_fils_amount_due(): void
    {
        [, $order, $customer] = $this->order('KWD', 1.125, 3);       // 3.375
        CustomerWalletService::credit($customer, 5, 'seed', 'seed:'.$customer->id);

        $applied = CustomerWalletService::applyToOrder($customer, $order, 5);

        // Batch 3: 3.38 → 3.37 (never over the due). Batch 4 · 1: the ledger posts fils, so the tender is the exact due.
        $this->assertEqualsWithDelta($this->ba(3.37, 3.375), $applied, self::D);
        $this->assertEqualsWithDelta($this->ba(0.005, 0.0), $order->fresh()->amount_due, self::D);
        $this->assertEqualsWithDelta($this->ba(1.63, 1.625), CustomerWalletService::balanceFor($customer->fresh()), self::D);
    }

    public function test_kwd_gift_card_never_exceeds_the_fils_amount_due(): void
    {
        [$r, $order] = $this->order('KWD', 1.125, 3);
        $card = $this->card($r, 5);

        $applied = GiftCardService::applyToOrder($card, $order);

        $this->assertEqualsWithDelta($this->ba(3.37, 3.375), $applied, self::D);         // batch 3 before: 3.38
        $this->assertEqualsWithDelta($this->ba(0.005, 0.0), $order->fresh()->amount_due, self::D);
        $this->assertEqualsWithDelta($this->ba(1.63, 1.625), $card->fresh()->balance(), self::D);
    }

    public function test_jpy_tenders_are_whole_yen_and_capped_at_balance(): void
    {
        [$r, $order, $customer] = $this->order('JPY', 333, 3);       // 999
        CustomerWalletService::credit($customer, 500, 'seed', 'seed:'.$customer->id);
        $card = $this->card($r, 1000);

        $this->assertEqualsWithDelta(500.0, CustomerWalletService::applyToOrder($customer, $order, 800), self::D);
        $this->assertEqualsWithDelta(499.0, GiftCardService::applyToOrder($card, $order->fresh()), self::D);
        $this->assertEqualsWithDelta(0.0, $order->fresh()->amount_due, self::D);
        $this->assertEqualsWithDelta(501.0, $card->fresh()->balance(), self::D);
    }

    public function test_gift_card_after_fusion_wallet_is_capped_at_what_is_still_due(): void
    {
        [$r, $order] = $this->order('USD', 10, 3);                    // 30.00
        $order->forceFill(['mk_wallet_applied' => 25])->save();      // the platform wallet already paid 25
        $card = $this->card($r, 50);

        $applied = GiftCardService::applyToOrder($card, $order->fresh());

        $this->assertEqualsWithDelta(5.0, $applied, self::D);                     // before: 30.00 (ignored the 25 already paid)
        $this->assertEqualsWithDelta(0.0, $order->fresh()->amount_due, self::D);  // before: -25.00
    }
}
