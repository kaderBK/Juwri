<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Http\Middleware\Installed;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * WI-06e — Tamara and Tabby: a notice settles only the transaction its
 * provider-side order / payment was created for at checkout. A genuinely
 * authenticated notice about another order or payment, a notice whose body
 * contradicts its signed token, or an expired token must not settle, and
 * must not authorise or capture anything either. Fake keys only.
 */
class BnplBindingTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
    }

    // ------------------------------------------------------------ Tamara

    private const TAMARA_CREDS = ['api_token' => 'tamara_tok_fake', 'notification_token' => 'tamara_nt_fake'];

    private function jwt(array $claims, string $secret = 'tamara_nt_fake'): string
    {
        $b64 = fn ($v) => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
        $signing = $b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT'])).'.'.$b64(json_encode($claims));

        return $signing.'.'.$b64(hash_hmac('sha256', $signing, $secret, true));
    }

    private function fakeTamara(): void
    {
        Http::fake([
            'api-sandbox.tamara.co/checkout' => Http::response(['order_id' => 'tam_ord_1', 'checkout_id' => 'chk_1', 'checkout_url' => 'https://checkout.tamara.co/x'], 200),
            'api-sandbox.tamara.co/orders/*/authorise' => Http::response(['status' => 'authorised'], 200),
            'api-sandbox.tamara.co/orders/*/capture' => Http::response(['capture_id' => 'cap_1'], 200),
        ]);
    }

    private function tamaraOrder(?string $tamaraOrderId = 'tam_ord_1'): array
    {
        [$store, $order, $tx] = $this->makeOrderTransaction('tamara', 250.0, self::TAMARA_CREDS);
        if ($tamaraOrderId !== null) {
            $tx->forceFill(['details' => ['tamara_order_id' => $tamaraOrderId]])->save();
        }

        return [$store, $order, $tx];
    }

    private function tamaraNotice(string $token, array $body)
    {
        return $this->withHeaders(['tamaraToken' => $token])->post('/webhook/tamara', $body);
    }

    private function assertTamaraUntouched(): void
    {
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/authorise') || str_contains($r->url(), '/capture'));
    }

    public function test_tamara_pay_records_the_order(): void
    {
        [$store, , $tx] = $this->tamaraOrder(null);
        StoreOption::updateStoreOption($store->id, 'restaurant_bnpl_enabled', '1');
        $this->fakeTamara();

        $this->post("/payment/{$tx->id}/pay", ['payment_method' => 'tamara', 'email' => 'c@e.com', 'phone' => '+96650', 'name' => 'C'])
            ->assertRedirect('https://checkout.tamara.co/x');
        $this->assertSame('tam_ord_1', Transaction::find($tx->id)->details->tamara_order_id);
    }

    public function test_tamara_checkout_without_an_order_id_does_not_hand_off(): void
    {
        [$post2, , $tx2] = $this->tamaraOrder(null);
        StoreOption::updateStoreOption($post2->id, 'restaurant_bnpl_enabled', '1');
        Http::fake(['api-sandbox.tamara.co/checkout' => Http::response(['checkout_url' => 'https://checkout.tamara.co/y'], 200)]);
        $res = $this->post("/payment/{$tx2->id}/pay", ['payment_method' => 'tamara', 'email' => 'c@e.com', 'phone' => '+96650', 'name' => 'C']);
        $this->assertNotSame('https://checkout.tamara.co/y', $res->headers->get('Location'));
        $this->assertNull(Transaction::find($tx2->id)->details);
    }

    public function test_tamara_genuine_notice_about_another_order_does_not_settle(): void
    {
        [, $order, $tx] = $this->tamaraOrder('tam_ord_1');
        $this->fakeTamara();
        // Correctly signed with the store's own notification token, but about
        // another Tamara order of the same merchant.
        $claims = ['order_id' => 'tam_ord_other', 'order_reference_id' => (string) $tx->id, 'event_type' => 'order_approved'];

        $this->tamaraNotice($this->jwt($claims), $claims)->assertOk()->assertSee('order mismatch');

        $this->assertNotSettled($order, $tx);
        $this->assertTamaraUntouched();
    }

    public function test_tamara_transaction_without_a_recorded_order_does_not_settle(): void
    {
        [, $order, $tx] = $this->tamaraOrder(null);
        $this->fakeTamara();
        $claims = ['order_id' => 'tam_ord_1', 'order_reference_id' => (string) $tx->id, 'event_type' => 'order_approved'];

        $this->tamaraNotice($this->jwt($claims), $claims)->assertOk();

        $this->assertNotSettled($order, $tx);
        $this->assertTamaraUntouched();
    }

    public function test_tamara_body_contradicting_the_signed_token_is_refused(): void
    {
        [, $order, $tx] = $this->tamaraOrder('tam_ord_1');
        [, $order2, $tx2] = $this->tamaraOrder('tam_ord_2');
        $this->fakeTamara();

        // A token issued for a declined event, replayed with an "approved" body.
        $declined = $this->jwt(['order_id' => 'tam_ord_1', 'order_reference_id' => (string) $tx->id, 'event_type' => 'order_declined']);
        $this->tamaraNotice($declined, ['order_id' => 'tam_ord_1', 'order_reference_id' => (string) $tx->id, 'event_type' => 'order_approved'])->assertStatus(401);

        // A token for one transaction, replayed with another transaction's body.
        $first = $this->jwt(['order_id' => 'tam_ord_1', 'order_reference_id' => (string) $tx->id, 'event_type' => 'order_approved']);
        $this->tamaraNotice($first, ['order_id' => 'tam_ord_2', 'order_reference_id' => (string) $tx2->id, 'event_type' => 'order_approved'])->assertStatus(401);

        $this->assertNotSettled($order, $tx);
        $this->assertNotSettled($order2, $tx2);
        $this->assertTamaraUntouched();
    }

    public function test_tamara_expired_token_is_refused_and_a_valid_bound_notice_settles_once(): void
    {
        [, $order, $tx] = $this->tamaraOrder('tam_ord_1');
        $this->fakeTamara();
        $claims = ['order_id' => 'tam_ord_1', 'order_reference_id' => (string) $tx->id, 'event_type' => 'order_approved'];

        $this->tamaraNotice($this->jwt($claims + ['exp' => time() - 60]), $claims)->assertStatus(401);
        $this->assertNotSettled($order, $tx);
        $this->assertTamaraUntouched();

        $fresh = $this->jwt($claims + ['iat' => time(), 'exp' => time() + 600]);
        $this->tamaraNotice($fresh, $claims)->assertOk();
        $this->tamaraNotice($fresh, $claims)->assertOk();

        $this->assertSettledOnce($order, $tx);
        Http::assertSentCount(2); // one authorise + one capture; the replay sends nothing
        Http::assertSent(fn ($r) => str_contains($r->url(), '/orders/tam_ord_1/capture'));
    }

    // ------------------------------------------------------------- Tabby

    private const TABBY_CREDS = ['secret_key' => 'sk_test_tabby_fake', 'public_key' => 'pk_test_tabby_fake', 'merchant_code' => 'mc_fake'];

    private function tabbyOrder(?string $paymentId = 'PAY_OURS'): array
    {
        [$store, $order, $tx] = $this->makeOrderTransaction('tabby', 100.0, self::TABBY_CREDS);
        if ($paymentId !== null) {
            $tx->forceFill(['payment_id' => $paymentId])->save();
        }

        return [$store, $order, $tx];
    }

    private function fakeTabbyPayment(string $id, string $status, string $reference, float $amount = 100.0, string $currency = 'SAR'): void
    {
        Http::fake([
            'api.tabby.ai/api/v2/payments/'.$id.'/captures' => Http::response(['status' => 'CLOSED'], 200),
            'api.tabby.ai/api/v2/payments/'.$id => Http::response([
                'id' => $id, 'status' => $status, 'amount' => $amount, 'currency' => $currency,
                'order' => ['reference_id' => $reference],
            ], 200),
        ]);
    }

    private function tabbyNotice(string $paymentId, Transaction $tx)
    {
        return $this->withHeaders(['X-Tabby-Auth' => 'sk_test_tabby_fake'])
            ->post('/webhook/tabby', ['id' => $paymentId, 'order' => ['reference_id' => (string) $tx->id]]);
    }

    public function test_tabby_another_genuine_payment_does_not_settle_the_transaction(): void
    {
        [, $order, $tx] = $this->tabbyOrder('PAY_OURS');
        // A real, closed payment of the same merchant for the same amount that
        // even names our reference — but it is not the payment created for us.
        $this->fakeTabbyPayment('PAY_OTHER', 'CLOSED', (string) $tx->id);

        $this->tabbyNotice('PAY_OTHER', $tx)->assertOk()->assertSee('payment mismatch');

        $this->assertNotSettled($order, $tx);
        Http::assertNothingSent();
    }

    public function test_tabby_transaction_without_a_recorded_payment_does_not_settle(): void
    {
        [, $order, $tx] = $this->tabbyOrder(null);
        $this->fakeTabbyPayment('PAY_OURS', 'CLOSED', (string) $tx->id);

        $this->tabbyNotice('PAY_OURS', $tx)->assertOk();

        $this->assertNotSettled($order, $tx);
        Http::assertNothingSent();
    }

    public function test_tabby_retrieved_payment_for_another_reference_amount_or_currency_is_not_captured(): void
    {
        [, $order, $tx] = $this->tabbyOrder('PAY_OURS');

        $this->fakeTabbyPayment('PAY_OURS', 'AUTHORIZED', 'someone-else');
        $this->tabbyNotice('PAY_OURS', $tx)->assertOk();
        $this->fakeTabbyPayment('PAY_OURS', 'AUTHORIZED', (string) $tx->id, 1.0);
        $this->tabbyNotice('PAY_OURS', $tx)->assertOk();
        $this->fakeTabbyPayment('PAY_OURS', 'AUTHORIZED', (string) $tx->id, 100.0, 'AED');
        $this->tabbyNotice('PAY_OURS', $tx)->assertOk();

        $this->assertNotSettled($order, $tx);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/captures'));
    }

    public function test_tabby_its_own_payment_is_captured_and_settles_once(): void
    {
        [, $order, $tx] = $this->tabbyOrder('PAY_OURS');
        $this->fakeTabbyPayment('PAY_OURS', 'AUTHORIZED', (string) $tx->id);

        $this->tabbyNotice('PAY_OURS', $tx)->assertOk();
        $this->tabbyNotice('PAY_OURS', $tx);

        $this->assertSettledOnce($order, $tx);
        $this->assertSame(1, count(array_filter(Http::recorded()->all(), fn ($pair) => str_contains($pair[0]->url(), '/captures'))));
    }

    public function test_tabby_pay_without_a_payment_id_does_not_hand_off(): void
    {
        [$store, , $tx] = $this->tabbyOrder(null);
        StoreOption::updateStoreOption($store->id, 'restaurant_bnpl_enabled', '1');
        Http::fake(['api.tabby.ai/api/v2/checkout' => Http::response(['web_url' => 'https://checkout.tabby.ai/x'], 200)]);

        $res = $this->post("/payment/{$tx->id}/pay", ['payment_method' => 'tabby', 'email' => 'c@e.com', 'phone' => '+96650', 'name' => 'C']);

        $this->assertNotSame('https://checkout.tabby.ai/x', $res->headers->get('Location'));
        $this->assertNull(Transaction::find($tx->id)->payment_id);
    }
}
