<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments\MenaAdapters;

use App\Http\Controllers\Admin\TransactionController;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use App\Services\GiftCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Jobs\CreateNotificationJob;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Contracts\CustomerGateways;
use Juwri\Modules\Payments\Contracts\StoreTransfers;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\GatewayAdapterController;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\WalletTransferController;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\WireTransferController;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Gateways\Settlement;
use Juwri\Modules\Payments\Services\StoreCustomerGateways;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * P1-23 part 6 — the manual tenders (wallet transfer, bank / wire transfer) on the adapter family. No provider confirms
 * them, so the party whose account receives the money confirms — never the other one:
 *  - a store's own customer's gift card / top-up / deposit: the customer sees the STORE's wallet numbers / bank details
 *    (never the platform's), the platform is not told, only the store OWNER confirms (Order Hub); the superadmin's
 *    "mark as paid" refuses it;
 *  - a platform purchase by bank transfer: the platform's bank details, the superadmin confirms; no store owner can.
 */
class ManualTransferAdaptersTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->shareLayoutGlobals(['company_bank_info' => 'PLATFORM BANK IBAN GB00 PLAT'], true);
        config(['settings.company_bank_info' => 'PLATFORM BANK IBAN GB00 PLAT']);
    }

    /** A SAR store with both manual tenders switched on with its own details. */
    private function store(): Store
    {
        [$store] = $this->makeOrderTransaction('wire_transfer', 1.0, ['title' => 'Bank transfer', 'bank_info' => "Store Bank\nIBAN SA00 STORE 1234"]);
        foreach ([
            'currency_code' => 'SAR',
            'restaurant_wallet_transfer_install' => '1',
            'restaurant_wallet_transfer_syriatel_number' => '0999111222',
        ] as $key => $value) {
            StoreOption::updateStoreOption($store->id, $key, $value);
        }

        return $store;
    }

    private function giftCard(Store $store, string $folder): Transaction
    {
        ['transaction' => $tx] = GiftCardService::initiate((int) $store->id, 25.0, $folder, ['purchaser_email' => 'buyer@example.com']);

        return $tx->fresh();
    }

    private function pay(string $controller, Transaction $tx): TestResponse
    {
        return TestResponse::fromBaseResponse(($controller)::pay($tx->fresh()));
    }

    private function owner(Store $store): User
    {
        return User::findOrFail($store->user_id);
    }

    private function assertFundedOnce(Store $store, Transaction $tx): void
    {
        $this->assertSame(Transaction::STATUS_SUCCESS, Transaction::find($tx->id)->status);
        $this->assertEqualsWithDelta(25.0, GiftCardService::liabilityFor((int) $store->id), 0.0001);
        $this->assertSame(1, DB::table('outbox_messages')->where('dedupe_key', Settlement::CREDIT.':'.$tx->id)->count());
    }

    private function assertNotFunded(Store $store, Transaction $tx): void
    {
        $this->assertNotSame(Transaction::STATUS_SUCCESS, Transaction::find($tx->id)->status);
        $this->assertEqualsWithDelta(0.0, GiftCardService::liabilityFor((int) $store->id), 0.0001);
    }

    public function test_both_are_adapters_offered_to_store_customers(): void
    {
        $store = $this->store();
        foreach (['wallet_transfer' => WalletTransferController::class, 'wire_transfer' => WireTransferController::class] as $folder => $controller) {
            $this->assertTrue(is_subclass_of($controller, GatewayAdapterController::class));
            $this->assertArrayHasKey($folder, StoreCustomerGateways::ADAPTER_FOLDERS);
            $this->assertSame($folder, app(CustomerGateways::class)->resolve((int) $store->id, $folder));
        }
    }

    public function test_a_gift_card_by_bank_transfer_shows_the_store_bank_details_and_tells_the_platform_nothing(): void
    {
        Bus::fake([CreateNotificationJob::class]);
        $store = $this->store();
        $tx = $this->giftCard($store, 'wire_transfer');

        $response = $this->pay(WireTransferController::class, $tx)->assertOk();

        $response->assertSee('IBAN SA00 STORE 1234');
        $response->assertDontSee('PLATFORM BANK');
        $response->assertSee('#'.$tx->id);
        $this->assertSame(Transaction::STATUS_PENDING, $tx->fresh()->status);
        $this->assertSame('wire_transfer', $tx->fresh()->transaction_gatway);
        Bus::assertNotDispatched(CreateNotificationJob::class);
    }

    public function test_a_store_without_bank_details_is_refused(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'restaurant_wire_transfer_bank_info', '');
        $tx = $this->giftCard($store, 'wire_transfer');

        $this->pay(WireTransferController::class, $tx)->assertRedirect(route('publicView', $store->slug));

        $this->assertNull($tx->fresh()->status);
    }

    public function test_only_the_store_owner_confirms_a_store_bank_transfer_once_and_the_superadmin_cannot(): void
    {
        $store = $this->store();
        $tx = $this->giftCard($store, 'wire_transfer');
        $this->pay(WireTransferController::class, $tx);

        // The superadmin cannot mark it paid (it went to the store's account) — and gets no button for it.
        $admin = User::factory()->create(['user_type' => 'admin', 'status' => 1, 'email_verified_at' => now()]);
        $this->actingAs($admin)->from(route('admin.transactions.index'))->put(route('admin.transactions.update', $tx))
            ->assertRedirect(route('admin.transactions.index'))->assertSessionHas('app_alert_type', 'error');
        $this->assertFalse(TransactionController::markablePaid($tx->fresh()));
        $this->assertNotFunded($store, $tx);

        // Another store's owner cannot either.
        $intruder = $this->owner($this->store());
        $this->actingAs($intruder)->postJson(route('stores.storeTransfer.confirm', $tx->id))->assertNotFound(); // BL-043: the owner tenant scope hides the other account's row (404)
        $this->assertNotFunded($store, $tx);

        // The Order Hub lists it for its own owner, who confirms it — once.
        $this->actingAs($this->owner($store))->get(route('stores.orders', $store->id))->assertOk()
            ->assertSee('Transfers to confirm')
            ->assertSeeHtml(route('stores.storeTransfer.confirm', $tx->id));
        $this->actingAs($this->owner($store))->postJson(route('stores.storeTransfer.confirm', $tx->id))->assertOk()->assertJson(['success' => true]);
        $this->actingAs($this->owner($store))->postJson(route('stores.storeTransfer.confirm', $tx->id))->assertForbidden();

        $this->assertFundedOnce($store, $tx);
        $this->assertSame([], app(StoreTransfers::class)->pendingForStore((int) $store->id)->all());
    }

    public function test_a_rejected_transfer_is_cancelled_and_never_credited(): void
    {
        $store = $this->store();
        $tx = $this->giftCard($store, 'wire_transfer');
        $this->pay(WireTransferController::class, $tx);

        $this->actingAs($this->owner($store))->postJson(route('stores.storeTransfer.reject', $tx->id))->assertOk();

        $this->assertSame('cancel', Transaction::find($tx->id)->status);
        $this->assertFalse(app(StoreTransfers::class)->confirm($tx->fresh(), (int) $store->user_id));
        $this->assertNotFunded($store, $tx);
    }

    public function test_a_platform_bank_transfer_shows_the_platform_details_and_no_store_owner_can_confirm_it(): void
    {
        Bus::fake([CreateNotificationJob::class]);
        $store = $this->store();
        [$buyer, $tx] = $this->makePlanTransaction(49.0, 'SAR', 'wallet_topup');
        $this->actingAs($buyer);
        request()->setUserResolver(fn () => $buyer);

        $response = $this->pay(WireTransferController::class, $tx)->assertOk();

        $this->assertSame('owner.billing.gateways.wire-transfer', $response->original->name());
        Bus::assertDispatched(CreateNotificationJob::class);
        $this->assertSame(Transaction::STATUS_PENDING, $tx->fresh()->status);
        $this->assertTrue(TransactionController::markablePaid($tx->fresh()));
        // Neither the buyer nor any store owner confirms a platform purchase.
        $this->assertFalse(app(StoreTransfers::class)->confirm($tx->fresh(), (int) $buyer->id));
        $this->actingAs($this->owner($store))->postJson(route('stores.storeTransfer.confirm', $tx->id))->assertForbidden();
        $this->assertSame(Transaction::STATUS_PENDING, $tx->fresh()->status);
    }

    public function test_an_order_is_not_paid_by_bank_transfer(): void
    {
        [, , $tx] = $this->makeOrderTransaction('wire_transfer', 10.0, ['bank_info' => 'IBAN']);

        $this->pay(WireTransferController::class, $tx)->assertRedirect(route('payment.index', $tx));

        $this->assertNull($tx->fresh()->status);
    }

    public function test_a_gift_card_by_wallet_transfer_is_claimed_then_confirmed_by_the_owner_once(): void
    {
        $store = $this->store();
        $tx = $this->giftCard($store, 'wallet_transfer');

        $page = $this->pay(WalletTransferController::class, $tx)->assertOk();
        $page->assertSee('0999111222')->assertSee('#'.$tx->id)->assertDontSee('Order number');
        $claimUrl = URL::signedRoute('storeTransfer.claim', ['transaction' => $tx->id]);
        $page->assertSeeHtml(e($claimUrl));

        // An unsigned claim is refused; the signed one records the claim (nothing settles).
        $this->post(route('storeTransfer.claim', $tx->id), ['wallet' => 'syriatel', 'txn_id' => 'X'])->assertForbidden();
        $this->post($claimUrl, ['wallet' => 'syriatel', 'txn_id' => 'WT-REF-9'])->assertRedirect(route('publicView', $store->slug));
        $this->assertSame('WT-REF-9', Transaction::find($tx->id)->details->txn_id);
        $this->assertNotFunded($store, $tx);

        $this->actingAs($this->owner($store))->get(route('stores.orders', $store->id))->assertOk()->assertSee('WT-REF-9');
        $this->actingAs($this->owner($store))->postJson(route('stores.storeTransfer.confirm', $tx->id))->assertOk();
        $this->actingAs($this->owner($store))->postJson(route('stores.storeTransfer.confirm', $tx->id))->assertForbidden();

        $this->assertFundedOnce($store, $tx);
    }

    public function test_a_platform_purchase_cannot_be_paid_into_a_store_wallet(): void
    {
        $this->store();
        [, $tx] = $this->makePlanTransaction(49.0, 'SAR', 'wallet_topup');

        $this->pay(WalletTransferController::class, $tx)->assertRedirect(route('home'));

        $this->assertNull($tx->fresh()->status);
    }

    public function test_concurrent_confirmations_credit_once(): void
    {
        $store = $this->store();
        $tx = $this->giftCard($store, 'wire_transfer');
        $this->pay(WireTransferController::class, $tx);
        $stale = Transaction::find($tx->id); // a second request that loaded the row before the first confirm

        $this->assertTrue(app(StoreTransfers::class)->confirm(Transaction::find($tx->id), (int) $store->user_id));
        $this->assertFalse(app(StoreTransfers::class)->confirm($stale, (int) $store->user_id));

        $this->assertFundedOnce($store, $tx);
    }
}
