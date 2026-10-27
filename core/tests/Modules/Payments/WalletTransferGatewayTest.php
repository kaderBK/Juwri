<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Notification;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * Syria A7 — wallet_transfer: a MANUAL, store-owner-confirmed tender.
 *
 * Unlike the redirect acquirers there is no remote verify: pay() renders the
 * store's wallet numbers, the customer submits a wallet+txn-id CLAIM, and the
 * owner confirms it (which is the ONLY path that calls paySuccess and marks the
 * order paid). These tests cover: pay renders the configured numbers + amount;
 * claim writes details + notifies; claim validation failures settle nothing;
 * owner-confirm settles (paySuccess side effects); reject leaves it unpaid;
 * a different store's owner is 403; a replayed confirm is a no-op.
 */
class WalletTransferGatewayTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);

        // APP_INSTALLED=false in tests, so the ViewServiceProvider globals are not
        // registered — share them like SparseOptionsRenderTest so the storefront
        // layout the pay view extends (and the dashboard Order Hub) can render.
        $settings = new class(['theme_color' => '#111', 'site_title' => 'TestApp', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
        {
            /** @param  array<string, mixed>  $data */
            public function __construct(private array $data) {}

            public function __get(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function __set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function __isset(string $key): bool
            {
                return array_key_exists($key, $this->data);
            }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect(), 'admin_languages' => collect(), 'pages' => collect()]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    /**
     * @return array{0:Store,1:Order,2:Transaction}
     */
    private function makeWalletOrder(float $amount = 12345.0, string $currency = 'SYP'): array
    {
        [$store, $order, $tx] = $this->makeOrderTransaction('wallet_transfer', $amount, [], $currency);
        StoreOption::updateStoreOption($store->id, 'restaurant_wallet_transfer_title', 'Pay by wallet');
        StoreOption::updateStoreOption($store->id, 'restaurant_wallet_transfer_syriatel_number', '0999111222');
        StoreOption::updateStoreOption($store->id, 'restaurant_wallet_transfer_shamcash_number', 'SHAM-77');
        StoreOption::updateStoreOption($store->id, 'restaurant_wallet_transfer_instructions', 'Send to the number then paste your reference.');
        StoreOption::updateStoreOption($store->id, 'currency_code', $currency);

        return [$store, $order, $tx];
    }

    public function test_pay_renders_configured_numbers_and_amount(): void
    {
        [, , $tx] = $this->makeWalletOrder();

        $res = $this->post("/payment/{$tx->id}/pay", ['payment_method' => 'wallet_transfer']);

        $res->assertOk();
        $res->assertSee('0999111222');   // configured Syriatel number
        $res->assertSee('SHAM-77');       // configured Sham Cash number
        $res->assertDontSee('MTN Cash');  // NOT configured → not shown
        $res->assertSee('12345.00');      // amount_due rendered (no thousands separator)
        $res->assertSee('Send to the number then paste your reference.'); // owner instructions

        // pay() marks the transaction PENDING under the wallet_transfer folder.
        $tx->refresh();
        $this->assertSame('wallet_transfer', $tx->transaction_gatway);
        $this->assertSame(Transaction::STATUS_PENDING, $tx->status);
    }

    public function test_claim_happy_path_writes_details_and_notifies(): void
    {
        [, $order, $tx] = $this->makeWalletOrder();
        $this->post("/payment/{$tx->id}/pay", ['payment_method' => 'wallet_transfer'])->assertOk();

        $before = Notification::count();

        $this->post($this->claimUrl($tx), ['wallet' => 'syriatel', 'txn_id' => 'REF-9001'])
            ->assertRedirect(route('store.orderTrack', $order->track_token));

        $tx->refresh();
        // details is 'object'-cast; the claim must be readable back (raw-array write).
        $this->assertSame('syriatel', $tx->details->wallet);
        $this->assertSame('REF-9001', $tx->details->txn_id);
        $this->assertNotEmpty($tx->details->claimed_at);

        // Still PENDING — a claim never settles the order.
        $this->assertSame(Transaction::STATUS_PENDING, $tx->status);
        $this->assertSame(0, (int) $order->fresh()->is_paid);

        // Owner was pinged (CreateNotificationJob runs after commit; forced here).
        $this->assertGreaterThan($before, Notification::count());
    }

    public function test_claim_rejects_wrong_gateway(): void
    {
        // A pending transaction that is NOT wallet_transfer must not be claimable.
        [, $order, $tx] = $this->makeOrderTransaction('fatora', 100.0, [], 'QAR');
        $tx->update(['transaction_gatway' => 'fatora', 'status' => Transaction::STATUS_PENDING]);

        $this->post($this->claimUrl($tx), ['wallet' => 'syriatel', 'txn_id' => 'REF-1'])
            ->assertStatus(422);

        $this->assertNull($tx->fresh()->details->wallet ?? null);
        $this->assertSame(0, (int) $order->fresh()->is_paid);
    }

    public function test_claim_rejects_wrong_status(): void
    {
        [, $order, $tx] = $this->makeWalletOrder();
        // Not yet through pay() → status is null, so it is not a claimable PENDING row.
        $this->post($this->claimUrl($tx), ['wallet' => 'syriatel', 'txn_id' => 'REF-1'])
            ->assertStatus(422);

        $this->assertNull($tx->fresh()->status);
        $this->assertSame(0, (int) $order->fresh()->is_paid);
    }

    public function test_claim_rejects_double_submit(): void
    {
        [, , $tx] = $this->makeWalletOrder();
        $this->post("/payment/{$tx->id}/pay", ['payment_method' => 'wallet_transfer'])->assertOk();

        $this->post($this->claimUrl($tx), ['wallet' => 'syriatel', 'txn_id' => 'REF-1'])->assertRedirect();
        // Second claim is refused — the first one stands.
        $this->post($this->claimUrl($tx), ['wallet' => 'shamcash', 'txn_id' => 'REF-2'])->assertStatus(422);

        $this->assertSame('REF-1', $tx->fresh()->details->txn_id);
    }

    /** The claimed order is settled through paySuccess when the owner confirms. */
    public function test_owner_confirm_marks_order_paid_via_paysuccess(): void
    {
        [$store, $order, $tx] = $this->makeWalletOrder(12345.0, 'SYP');
        $this->post("/payment/{$tx->id}/pay", ['payment_method' => 'wallet_transfer'])->assertOk();
        $this->post($this->claimUrl($tx), ['wallet' => 'syriatel', 'txn_id' => 'REF-1'])->assertRedirect();

        $owner = User::find($store->user_id);
        $this->actingAs($owner)
            ->postJson(route('stores.walletTransfer.confirm', $order->id))
            ->assertOk()->assertJson(['success' => true]);

        // paySuccess side effects: order paid + gateway stamped, transaction consumed.
        $fresh = $order->fresh();
        $this->assertSame(1, (int) $fresh->is_paid);
        $this->assertSame('wallet_transfer', $fresh->payment_gateway);
        $this->assertSame(Transaction::STATUS_CONSUMED, Transaction::find($tx->id)->status);

        // Wallet was credited with the amount (read the row directly — bypass memo).
        $wallet = (float) StoreSettings::legacy((int) $store->id, 'wallet_amount');
        $this->assertEqualsWithDelta(12345.0, $wallet, 0.01);
    }

    /** A rejected claim leaves the order unpaid and moves no money. */
    public function test_owner_reject_leaves_order_unpaid(): void
    {
        [$store, $order, $tx] = $this->makeWalletOrder();
        $this->post("/payment/{$tx->id}/pay", ['payment_method' => 'wallet_transfer'])->assertOk();
        $this->post($this->claimUrl($tx), ['wallet' => 'syriatel', 'txn_id' => 'REF-1'])->assertRedirect();

        $owner = User::find($store->user_id);
        $this->actingAs($owner)
            ->postJson(route('stores.walletTransfer.reject', $order->id))
            ->assertOk()->assertJson(['success' => true]);

        $this->assertSame(0, (int) $order->fresh()->is_paid);
        $fresh = Transaction::find($tx->id);
        $this->assertTrue((bool) ($fresh->details->rejected ?? false));
        // Still PENDING (not consumed) — nothing settled.
        $this->assertSame(Transaction::STATUS_PENDING, $fresh->status);
        $wallet = (float) StoreSettings::legacy((int) $store->id, 'wallet_amount');
        $this->assertEqualsWithDelta(0.0, $wallet, 0.01);
    }

    /** Store scoping: a DIFFERENT store's owner cannot confirm/reject — 403. */
    public function test_other_store_owner_cannot_confirm_or_reject(): void
    {
        [$store, $order, $tx] = $this->makeWalletOrder();
        $this->post("/payment/{$tx->id}/pay", ['payment_method' => 'wallet_transfer'])->assertOk();
        $this->post($this->claimUrl($tx), ['wallet' => 'syriatel', 'txn_id' => 'REF-1'])->assertRedirect();

        $intruder = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        $this->actingAs($intruder)
            ->postJson(route('stores.walletTransfer.confirm', $order->id))
            ->assertNotFound(); // BL-043: the owner tenant scope hides the other account's row (404)
        $this->actingAs($intruder)
            ->postJson(route('stores.walletTransfer.reject', $order->id))
            ->assertNotFound();

        $this->assertSame(0, (int) $order->fresh()->is_paid);
        $this->assertSame(Transaction::STATUS_PENDING, Transaction::find($tx->id)->status);
    }

    /** The Order Hub surfaces the claim card (a wallet_transfer order is 'unpaid'
     *  and hidden from the queue, so the claim must appear in its own section). */
    public function test_order_hub_shows_the_claim_card(): void
    {
        [$store, $order, $tx] = $this->makeWalletOrder();
        // The order is 'unpaid' until confirmed (as in production for pay-online).
        $order->update(['status' => 'unpaid']);
        $this->post("/payment/{$tx->id}/pay", ['payment_method' => 'wallet_transfer'])->assertOk();
        $this->post($this->claimUrl($tx), ['wallet' => 'syriatel', 'txn_id' => 'REF-CARD-1'])->assertRedirect();

        $owner = User::find($store->user_id);
        $res = $this->actingAs($owner)->get(route('stores.orders', $store->id));

        $res->assertStatus(200);
        $res->assertSee('Wallet-transfer payments to confirm');
        $res->assertSee('REF-CARD-1');                 // the claimed txn id
        $res->assertSeeHtml('Verify it against your wallet SMS'); // the warning
        $res->assertSeeHtml(route('stores.walletTransfer.confirm', $order->id));
        $res->assertSeeHtml(route('stores.walletTransfer.reject', $order->id));
    }

    /** A5: the order-track page shows the shared pending partial (DOM contract)
     *  for a claimed wallet_transfer order. */
    public function test_track_page_shows_pending_partial_when_claimed(): void
    {
        [$store, $order, $tx] = $this->makeWalletOrder();
        $order->update(['status' => 'unpaid']);
        $this->post("/payment/{$tx->id}/pay", ['payment_method' => 'wallet_transfer'])->assertOk();
        $this->post($this->claimUrl($tx), ['wallet' => 'syriatel', 'txn_id' => 'REF-TRK-1'])->assertRedirect();

        $res = $this->get(route('store.orderTrack', $order->track_token));

        $res->assertStatus(200);
        $res->assertSeeHtml('data-mb-paypending');       // DOM contract root
        $res->assertSeeHtml('data-state="claimed"');      // claimed state
        $res->assertSeeHtml('mb-paypending-txn');         // txn element
        $res->assertSee('REF-TRK-1');                        // the claimed txn id
    }

    /** Replay: a second confirm is a no-op — order paid once, wallet credited once. */
    public function test_second_confirm_is_a_noop(): void
    {
        [$store, $order, $tx] = $this->makeWalletOrder(500.0, 'SYP');
        $this->post("/payment/{$tx->id}/pay", ['payment_method' => 'wallet_transfer'])->assertOk();
        $this->post($this->claimUrl($tx), ['wallet' => 'syriatel', 'txn_id' => 'REF-1'])->assertRedirect();

        $owner = User::find($store->user_id);
        $this->actingAs($owner)->postJson(route('stores.walletTransfer.confirm', $order->id))->assertOk();
        // Second confirm: the row is already consumed, so there is no pending claim.
        $this->actingAs($owner)->postJson(route('stores.walletTransfer.confirm', $order->id))->assertStatus(422);

        $this->assertSame(1, (int) $order->fresh()->is_paid);
        // Credited exactly once (paySuccess CAS on status != consumed guarantees it).
        $wallet = (float) StoreSettings::legacy((int) $store->id, 'wallet_amount');
        $this->assertEqualsWithDelta(500.0, $wallet, 0.01);
    }

    /** P6-154: the claim URL the pay page renders (signed, like storeTransfer.claim). */
    private function claimUrl(Transaction $tx): string
    {
        return URL::signedRoute('payment.claim', ['transaction' => $tx->id]);
    }

    /** P6-154: a bare sequential-id claim is refused — it used to write the claim and redirect to the track page. */
    public function test_unsigned_claim_is_refused_and_writes_nothing(): void
    {
        [, $order, $tx] = $this->makeWalletOrder();
        $this->post("/payment/{$tx->id}/pay", ['payment_method' => 'wallet_transfer'])->assertOk();

        $res = $this->post("/payment/{$tx->id}/claim", ['wallet' => 'syriatel', 'txn_id' => 'REF-X']);
        $res->assertForbidden();
        $this->assertStringNotContainsString((string) $order->track_token, (string) $res->headers->get('Location'));
        $this->assertEmpty(optional($tx->fresh()->details)->claimed_at);

        // A signature for another transaction does not carry over.
        [, , $other] = $this->makeWalletOrder();
        $forged = str_replace('/payment/'.$other->id.'/', '/payment/'.$tx->id.'/', $this->claimUrl($other));
        $this->post($forged, ['wallet' => 'syriatel', 'txn_id' => 'REF-Y'])->assertForbidden();
        $this->assertEmpty(optional($tx->fresh()->details)->claimed_at);
    }
}
