<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments\MenaAdapters;

use App\Http\Middleware\Installed;
use App\Models\StoreOption;
use App\Services\GiftCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Juwri\Modules\Payments\Contracts\CustomerGateways;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\GatewayAdapterController;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\GocardlessController;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Gateways\Settlement;
use Juwri\Modules\Payments\Services\StoreCustomerGateways;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * P1-23 part 6 — GoCardless on the adapter family, PLATFORM PURCHASES ONLY. It is a Direct Debit mandate flow: the
 * payer authorises a mandate and the money is collected days later, confirmed by a later webhook — it fits plan /
 * add-on billing, not a store customer's gift card, top-up or deposit (and stores have no GoCardless settings). So it
 * is never offered to store customers and refuses orders and store payments before any call.
 */
class GocardlessPlatformOnlyTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    private const SECRET = 'whsec_platform';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
        config([
            'settings.gocardless_access_token' => 'gc_platform',
            'settings.gocardless_webhook_secret' => self::SECRET,
            'settings.gocardless_sandbox' => true,
        ]);
    }

    private function fakeProvider(int $amountMinor, string $currency, string $txId): void
    {
        Http::fake([
            'api-sandbox.gocardless.com/billing_requests' => Http::response(['billing_requests' => ['id' => 'BRQ1']], 201),
            'api-sandbox.gocardless.com/billing_request_flows' => Http::response(['billing_request_flows' => ['authorisation_url' => 'https://pay.gocardless.test/flow']], 201),
            'api-sandbox.gocardless.com/payments/*' => Http::response(['payments' => [
                'id' => 'PM1', 'status' => 'confirmed', 'amount' => $amountMinor, 'currency' => $currency, 'metadata' => ['transaction_id' => $txId],
            ]]),
        ]);
    }

    private function confirmed(string $eventId = 'EV1', string $secret = self::SECRET): TestResponse
    {
        $body = (string) json_encode(['events' => [['id' => $eventId, 'resource_type' => 'payments', 'action' => 'confirmed', 'links' => ['payment' => 'PM1']]]]);

        return $this->call('POST', '/webhook/gocardless', [], [], [], [
            'HTTP_WEBHOOK_SIGNATURE' => hash_hmac('sha256', $body, $secret), 'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    public function test_it_is_an_adapter_for_platform_purchases_only_and_never_offered_to_store_customers(): void
    {
        $this->assertTrue(is_subclass_of(GocardlessController::class, GatewayAdapterController::class));
        $this->assertContains('gocardless', StoreCustomerGateways::PLATFORM_ONLY);
        $this->assertArrayNotHasKey('gocardless', StoreCustomerGateways::ADAPTER_FOLDERS);

        [$store] = $this->makeOrderTransaction('gocardless', 10.0, [], 'GBP'); // even with a store option switched on
        $this->assertNull(app(CustomerGateways::class)->resolve((int) $store->id, 'gocardless'));
        $this->assertSame([], app(CustomerGateways::class)->forStore((int) $store->id)->pluck('id')->all());
    }

    public function test_a_store_payment_and_an_order_are_refused_before_any_call(): void
    {
        Http::fake();
        [$store, , $order] = $this->makeOrderTransaction('gocardless', 10.0, [], 'GBP');
        StoreOption::updateStoreOption($store->id, 'currency_code', 'GBP');
        ['transaction' => $giftCard] = GiftCardService::initiate((int) $store->id, 25.0, 'gocardless', ['purchaser_email' => 'b@example.com']);

        foreach ([$giftCard, $order] as $tx) {
            try {
                GocardlessController::pay($tx->fresh());
                $this->fail('refused');
            } catch (HttpException $e) {
                $this->assertSame(404, $e->getStatusCode());
            }
        }
        Http::assertNothingSent();
    }

    public function test_a_platform_purchase_uses_the_platform_account_in_minor_units(): void
    {
        [, $tx] = $this->makePlanTransaction(49.99, 'EUR', 'wallet_topup');
        $this->fakeProvider(4999, 'EUR', (string) $tx->id);

        $this->assertSame('https://pay.gocardless.test/flow', GocardlessController::pay($tx)->headers->get('Location'));

        Http::assertSent(fn (ClientRequest $r) => str_ends_with($r->url(), '/billing_requests')
            && $r->hasHeader('Authorization', 'Bearer gc_platform')
            && $r['billing_requests']['payment_request']['amount'] === 4999);
    }

    public function test_a_currency_gocardless_cannot_collect_is_refused_before_any_call(): void
    {
        Http::fake();
        [, $tx] = $this->makePlanTransaction(49.0, 'SAR', 'wallet_topup');

        app()->setLocale('en');
        GocardlessController::pay($tx);

        Http::assertNothingSent();
        $this->assertSame('This payment method cannot charge in SAR. Please choose another payment method.', session('app_alert_message'));
    }

    public function test_amount_or_currency_mismatch_is_refused(): void
    {
        [, $tx] = $this->makePlanTransaction(49.0, 'GBP', 'wallet_topup');

        $this->fakeProvider(4899, 'GBP', (string) $tx->id);
        $this->confirmed('EV1')->assertOk();
        $this->assertNull(Transaction::find($tx->id)->status);

        $this->fakeProvider(4900, 'EUR', (string) $tx->id);
        $this->confirmed('EV2')->assertOk();
        $this->assertNull(Transaction::find($tx->id)->status);
    }

    public function test_a_confirmed_payment_naming_a_store_payment_never_settles(): void
    {
        [$store] = $this->makeOrderTransaction('gocardless', 10.0, [], 'GBP');
        StoreOption::updateStoreOption($store->id, 'currency_code', 'GBP');
        ['transaction' => $giftCard] = GiftCardService::initiate((int) $store->id, 25.0, 'gocardless', ['purchaser_email' => 'b@example.com']);
        $this->fakeProvider(2500, 'GBP', (string) $giftCard->id);

        $this->confirmed()->assertOk();

        $this->assertNull(Transaction::find($giftCard->id)->status);
        $this->assertEqualsWithDelta(0.0, GiftCardService::liabilityFor((int) $store->id), 0.0001);
    }

    public function test_an_unsigned_or_wrongly_signed_event_is_401(): void
    {
        [, $tx] = $this->makePlanTransaction(49.0, 'GBP', 'wallet_topup');
        $this->fakeProvider(4900, 'GBP', (string) $tx->id);

        $this->confirmed('EV1', 'not-the-secret')->assertStatus(401);

        $this->assertNull(Transaction::find($tx->id)->status);
        Http::assertNothingSent();
    }

    public function test_a_payment_started_on_the_old_controller_settles_once(): void
    {
        $this->requiresEditionPackage('a platform wallet top-up is credited by Billing');
        [, $tx] = $this->makePlanTransaction(49.0, 'GBP', 'wallet_topup');
        $tx->forceFill(['transaction_gatway' => 'gocardless'])->save(); // what the old pay() stored
        $this->fakeProvider(4900, 'GBP', (string) $tx->id);

        $this->confirmed('EV1')->assertOk();
        $this->confirmed('EV2')->assertOk(); // a second confirmation (paid) of the same payment

        $this->assertSame(Transaction::STATUS_SUCCESS, Transaction::find($tx->id)->status);
        $this->assertSame(1, DB::table('outbox_messages')->where('dedupe_key', Settlement::CREDIT.':'.$tx->id)->count());
    }
}
