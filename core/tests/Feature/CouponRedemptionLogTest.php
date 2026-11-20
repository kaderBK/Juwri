<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Loyalty\Models\CouponRedemption;
use Juwri\Modules\Loyalty\Services\CouponRedemptionLog;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\TestCase;

class CouponRedemptionLogTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $otherOwner;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
        $this->shareLayoutGlobals();

        $this->store = Store::factory()->create();
        $this->owner = User::findOrFail($this->store->user_id);

        $otherStore = Store::factory()->create();
        $this->otherOwner = User::findOrFail($otherStore->user_id);
    }

    private function makeCoupon(array $attrs = []): Coupon
    {
        $coupon = new Coupon;
        $coupon->fill($attrs + [
            'code' => 'C5'.Str::upper(Str::random(6)),
            'percentage' => 20,
            'limit' => 50,
            'used' => 0,
            'coupon_type' => 'percentage',
            'issuer_scope' => 'owner',
            'applies_to' => 'food_order',
            'user_id' => $this->owner->id,
        ]);
        $coupon->save();

        return $coupon;
    }

    public function test_rows_returns_order_slot_and_transaction_rows_newest_first(): void
    {
        $storeCoupon = $this->makeCoupon(['code' => 'STORE20']);
        $customer = Customer::create([
            'store_id' => $this->store->id,
            'name' => 'Alice Diner',
            'phone' => '5'.Str::random(8),
        ]);

        // 1. Order-based row (store_order) - oldest
        $order = new Order;
        $order->forceFill([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'coupon_id' => $storeCoupon->id,
            'discount_amount_minor' => 450,
            'currency_code' => 'USD',
            'grand_total' => 20,
            'status' => 'completed',
            'source' => 'web',
            'created_at' => Carbon::parse('2026-10-01 10:00:00'),
        ])->save();

        // Cancelled order must be skipped.
        $cancelled = new Order;
        $cancelled->forceFill([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'coupon_id' => $storeCoupon->id,
            'discount_amount_minor' => 999,
            'currency_code' => 'USD',
            'grand_total' => 10,
            'status' => 'cancelled',
            'cancelled_at' => Carbon::parse('2026-10-01 11:00:00'),
            'created_at' => Carbon::parse('2026-10-01 10:30:00'),
        ])->save();

        // 2. Slot-only row (order_id points to a gone/non-existent order)
        $slot = new CouponRedemption;
        $slot->forceFill([
            'coupon_id' => $storeCoupon->id,
            'customer_id' => $customer->id,
            'seq' => 1,
            'order_id' => 999999,
            'created_at' => Carbon::parse('2026-10-02 10:00:00'),
        ])->save();

        // Slot pointing to an existing order must NOT duplicate the order row.
        $dupSlot = new CouponRedemption;
        $dupSlot->forceFill([
            'coupon_id' => $storeCoupon->id,
            'customer_id' => $customer->id,
            'seq' => 2,
            'order_id' => $order->id,
            'created_at' => Carbon::parse('2026-10-01 10:00:00'),
        ])->save();

        // 3. Successful transaction rows (percentage and fixed capped at base)
        $pctCoupon = $this->makeCoupon([
            'code' => 'PLAN25',
            'issuer_scope' => 'platform',
            'applies_to' => 'plan_checkout',
            'user_id' => null,
            'coupon_type' => 'percentage',
            'percentage' => 25,
        ]);
        $fixedCoupon = $this->makeCoupon([
            'code' => 'FIX50',
            'issuer_scope' => 'platform',
            'applies_to' => 'plan_checkout',
            'user_id' => null,
            'coupon_type' => 'fixed',
            'percentage' => 50,
        ]);

        $payer = User::factory()->create(['name' => 'Bob Merchant', 'status' => 1]);

        $pctTx = new Transaction;
        $pctTx->forceFill([
            'product_name' => 'Pro Plan',
            'product_id' => 1,
            'user_id' => $payer->id,
            'status' => Transaction::STATUS_SUCCESS,
            'base_amount_minor' => 2000,
            'amount_minor' => 1500,
            'currency_code' => 'USD',
            'coupon' => ['id' => $pctCoupon->id, 'code' => $pctCoupon->code, 'coupon_type' => 'percentage', 'percentage' => 25],
            'created_at' => Carbon::parse('2026-10-03 10:00:00'),
        ])->save();

        // Fixed coupon of 5000 minor on base 3000 minor -> capped at 3000 minor (newest)
        $fixedTx = new Transaction;
        $fixedTx->forceFill([
            'product_name' => 'Starter Plan',
            'product_id' => 2,
            'user_id' => $payer->id,
            'status' => Transaction::STATUS_SUCCESS,
            'base_amount_minor' => 3000,
            'amount_minor' => 0,
            'currency_code' => 'EUR',
            'coupon' => ['id' => $fixedCoupon->id, 'code' => $fixedCoupon->code, 'coupon_type' => 'fixed'],
            'created_at' => Carbon::parse('2026-10-04 10:00:00'),
        ])->save();

        // Non-success transaction must be skipped.
        $pendingTx = new Transaction;
        $pendingTx->forceFill([
            'product_name' => 'Pending Plan',
            'product_id' => 3,
            'user_id' => $payer->id,
            'status' => 'pending',
            'base_amount_minor' => 3000,
            'amount_minor' => 3000,
            'currency_code' => 'USD',
            'coupon' => ['id' => $pctCoupon->id, 'code' => $pctCoupon->code, 'coupon_type' => 'percentage', 'percentage' => 25],
            'created_at' => Carbon::parse('2026-10-05 10:00:00'),
        ])->save();

        $rows = app(CouponRedemptionLog::class)->rows([$storeCoupon->id, $pctCoupon->id, $fixedCoupon->id]);

        $this->assertCount(4, $rows);

        // Newest first: fixedTx (Oct 4) -> pctTx (Oct 3) -> slot (Oct 2) -> order (Oct 1)
        $this->assertSame('transaction', $rows[0]['ref_type']);
        $this->assertSame((int) $fixedTx->id, $rows[0]['ref_id']);
        $this->assertSame(3000, $rows[0]['saved_minor']);
        $this->assertSame('EUR', $rows[0]['currency']);
        $this->assertSame(CouponRedemptionLog::SURFACE_PLAN, $rows[0]['surface']);

        $this->assertSame('transaction', $rows[1]['ref_type']);
        $this->assertSame((int) $pctTx->id, $rows[1]['ref_id']);
        $this->assertSame(500, $rows[1]['saved_minor']);
        $this->assertSame('Bob Merchant', $rows[1]['who_name']);
        $this->assertSame('user', $rows[1]['who_type']);

        $this->assertSame('order', $rows[2]['ref_type']);
        $this->assertSame(999999, $rows[2]['ref_id']);
        $this->assertNull($rows[2]['saved_minor']);
        $this->assertSame('Alice Diner', $rows[2]['who_name']);
        $this->assertSame('customer', $rows[2]['who_type']);

        $this->assertSame('order', $rows[3]['ref_type']);
        $this->assertSame((int) $order->id, $rows[3]['ref_id']);
        $this->assertSame(450, $rows[3]['saved_minor']);
        $this->assertSame(CouponRedemptionLog::SURFACE_STORE_ORDER, $rows[3]['surface']);
    }

    public function test_store_owner_log_shows_own_coupon_and_404s_on_another_owners_coupon(): void
    {
        $ownCoupon = $this->makeCoupon(['code' => 'OWNLOG1', 'user_id' => $this->owner->id]);
        $otherCoupon = $this->makeCoupon(['code' => 'OTHERLOG1', 'user_id' => $this->otherOwner->id]);

        $customer = Customer::create([
            'store_id' => $this->store->id,
            'name' => 'Owner Diner',
            'phone' => '5'.Str::random(8),
        ]);
        $order = new Order;
        $order->forceFill([
            'store_id' => $this->store->id,
            'customer_id' => $customer->id,
            'coupon_id' => $ownCoupon->id,
            'discount_amount_minor' => 250,
            'currency_code' => 'USD',
            'grand_total' => 15,
            'status' => 'completed',
        ])->save();

        $this->actingAs($this->owner)
            ->get(route('stores.coupons.redemptions', $ownCoupon->id))
            ->assertOk()
            ->assertSee('OWNLOG1')
            ->assertSee('Owner Diner');

        $this->actingAs($this->owner)
            ->get(route('stores.coupons.redemptions', $otherCoupon->id))
            ->assertNotFound();
    }

    public function test_admin_log_requires_authorization(): void
    {
        $admin = User::factory()->create([
            'group_id' => '1',
            'user_type' => 'admin',
            'status' => 1,
            'email_verified_at' => now(),
        ]);
        $nonAdmin = User::factory()->create([
            'group_id' => '2',
            'user_type' => 'user',
            'status' => 1,
            'email_verified_at' => now(),
        ]);

        $coupon = $this->makeCoupon([
            'code' => 'ADMINLOG1',
            'issuer_scope' => 'platform',
            'applies_to' => 'plan_checkout',
            'user_id' => null,
        ]);

        // Guest or non-admin cannot access admin redemption log.
        $this->get(route('admin.coupons.redemptions', $coupon->id))->assertRedirect();
        $this->actingAs($nonAdmin)->get(route('admin.coupons.redemptions', $coupon->id))->assertRedirect();

        // Authorized admin sees the log.
        $this->actingAs($admin)
            ->get(route('admin.coupons.redemptions', $coupon->id))
            ->assertOk()
            ->assertSee('ADMINLOG1');
    }
}
