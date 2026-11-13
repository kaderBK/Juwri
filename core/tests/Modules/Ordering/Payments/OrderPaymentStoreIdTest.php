<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering\Payments;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Ordering\Http\Controllers\User\PaymentController;
use Juwri\Modules\Payments\Models\BillingReceipt;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Gateways\Settlement;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * P1-10 / P1-04: an order payment carries its store in `transaction.store_id`; `user_id` is only ever a users.id
 * (the paying owner of a plan / add-on / top-up) and is NULL on order payments. Before, the store id was written into
 * user_id (FK to users → failed on MySQL/PostgreSQL, and joined order payments to whichever user had that id).
 * The ids are made to differ on purpose so nothing can pass by an id coincidence.
 */
class OrderPaymentStoreIdTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    public function test_an_order_payment_is_keyed_by_store_and_settles_into_that_store(): void
    {
        User::factory()->count(3)->create(); // user ids now run ahead of store ids
        [$store, $order, $tx] = $this->makeOrderTransaction('stripe', 20.0, [], 'SAR');
        $this->assertNotSame($store->id, $store->user_id);

        $tx->refresh();
        $this->assertSame($store->id, (int) $tx->store_id);
        $this->assertNull($tx->user_id);
        $this->assertSame($store->fresh()->account_id, $tx->account_id, 'an order payment belongs to the store account');
        $this->assertSame($store->id, Settlement::store($tx)?->id);
        $this->assertSame($store->id, $tx->store?->id);

        ob_start();
        PaymentController::paySuccess($tx);
        ob_get_clean();

        $this->assertSame(1, (int) $order->fresh()->is_paid);
        $this->assertEquals(20.0, (float) store_options($store->id, 'wallet_amount'));
        $receipt = BillingReceipt::where('transaction_id', $tx->id)->firstOrFail();
        $this->assertSame($store->id, (int) $receipt->store_id);
        $this->assertNull($receipt->user_id);
        $this->assertSame($store->fresh()->account_id, $receipt->account_id);
    }

    public function test_an_order_payment_of_a_store_whose_id_is_no_user_id_is_stored(): void
    {
        [$store, , $tx] = $this->makeOrderTransaction('stripe', 5.0);
        $this->seedStores(987654);
        $tx->forceFill(['store_id' => 987654])->save(); // FK users would have refused this id in user_id

        $this->assertSame(987654, (int) $tx->fresh()->store_id);
        $this->assertNull(User::find(987654));
        $this->assertNotSame($store->id, 987654);
    }

    public function test_a_platform_payment_keeps_the_paying_user_and_no_store(): void
    {
        $owner = User::factory()->create();
        Store::create([
            'user_id' => $owner->id, 'slug' => 's-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'R',
            'sub_title' => 'x', 'description' => 'x', 'main_image' => 'd.png', 'cover_image' => 'd.png',
            'address' => '1', 'phone' => '1',
        ]);
        $tx = Transaction::create([
            'user_id' => $owner->id, 'amount' => 9, 'currency_code' => 'USD', 'product_name' => 'Plan',
            'transaction_method' => 'membership',
        ]);

        $this->assertNull($tx->fresh()->store_id);
        $this->assertSame($owner->id, (int) $tx->fresh()->user_id);
        $this->assertSame($owner->fresh()->account_id, $tx->fresh()->account_id, 'a platform payment belongs to the payer account');
        $this->assertNull(Settlement::store($tx));
    }
}
