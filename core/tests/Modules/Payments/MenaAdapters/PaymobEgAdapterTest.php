<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments\MenaAdapters;

use App\Models\StoreOption;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Juwri\Core\Models\Store;
use Juwri\Modules\Payments\Services\Gateways\PaymobAdapter;

/**
 * P6-108b — Paymob for an Egyptian store: every adapter-family check of {@see PaymobAdapterTest} (initiate, the
 * HMAC-verified callback, once-only credit, mismatch refusals, old-controller state) re-run in EGP for a store whose
 * country is EG, plus the region host: Egypt talks to accept.paymob.com, never ksa.paymob.com; an explicit region
 * setting wins; KSA stays the default (HTTP faked, invented keys).
 */
class PaymobEgAdapterTest extends PaymobAdapterTest
{
    protected function currency(): string
    {
        return 'EGP';
    }

    protected function otherCurrency(): string
    {
        return 'SAR';
    }

    protected function storeWithGateway(): Store
    {
        $store = parent::storeWithGateway();
        StoreOption::updateStoreOption($store->id, 'fiscal_country', 'EG');

        return $store;
    }

    private function onHost(string $host): \Closure
    {
        return fn (ClientRequest $r) => str_starts_with($r->url(), $host.'/v1/intention/');
    }

    public function test_an_egyptian_store_talks_to_the_egypt_host_for_intention_and_checkout(): void
    {
        $store = $this->storeWithGateway();
        $tx = $this->giftCard($store);

        $response = $this->pay($tx);

        $this->assertStringStartsWith('https://accept.paymob.com/unifiedcheckout/?publicKey=pk_store&clientSecret=', (string) $response->headers->get('Location'));
        Http::assertSent($this->onHost('https://accept.paymob.com'));
        Http::assertNotSent($this->onHost('https://ksa.paymob.com'));
    }

    public function test_the_explicit_region_setting_wins_over_the_store_country(): void
    {
        $store = $this->storeWithGateway();
        StoreOption::updateStoreOption($store->id, 'restaurant_paymob_region', 'uae');
        $tx = $this->giftCard($store);

        $response = $this->pay($tx);

        $this->assertStringStartsWith('https://uae.paymob.com/unifiedcheckout/', (string) $response->headers->get('Location'));
        Http::assertSent($this->onHost('https://uae.paymob.com'));
        Http::assertNotSent($this->onHost('https://accept.paymob.com'));
    }

    public function test_a_saudi_store_keeps_the_ksa_host(): void
    {
        [$store] = $this->makeOrderTransaction('paymob', 1.0, $this->storeCreds(), 'SAR');
        StoreOption::updateStoreOption($store->id, 'currency_code', 'SAR');
        StoreOption::updateStoreOption($store->id, 'fiscal_country', 'SA');
        $tx = $this->giftCard($store);

        $response = $this->pay($tx);

        $this->assertStringStartsWith('https://ksa.paymob.com/unifiedcheckout/', (string) $response->headers->get('Location'));
        Http::assertSent($this->onHost('https://ksa.paymob.com'));
        Http::assertNotSent($this->onHost('https://accept.paymob.com'));
    }

    public function test_the_platform_account_region_follows_its_setting_then_the_currency(): void
    {
        [, $egp] = $this->makePlanTransaction(49.0, 'EGP', 'wallet_topup');
        $this->assertSame('eg', (new PaymobAdapter)->region($egp));

        config(['settings.paymob_region' => 'ksa']);
        $this->assertSame('ksa', (new PaymobAdapter)->region($egp->fresh()));
    }

    public function test_region_resolution_order_and_unknown_values(): void
    {
        $this->assertSame('eg', PaymobAdapter::resolveRegion(null, 'EG', 'USD'));
        $this->assertSame('ksa', PaymobAdapter::resolveRegion('', 'SA', 'EGP'), 'the country beats the currency');
        $this->assertSame('uae', PaymobAdapter::resolveRegion(null, 'ae', null));
        $this->assertSame('omn', PaymobAdapter::resolveRegion(null, 'OM', null));
        $this->assertSame('eg', PaymobAdapter::resolveRegion(null, null, 'egp'));
        $this->assertSame('omn', PaymobAdapter::resolveRegion('OMN', 'EG', 'EGP'), 'the setting beats everything');
        $this->assertSame('eg', PaymobAdapter::resolveRegion('https://evil.example', 'EG', null), 'an unknown setting is ignored');
        $this->assertSame('ksa', PaymobAdapter::resolveRegion(null, 'JO', 'JOD'), 'nothing names a region: KSA as before');
        foreach (PaymobAdapter::HOSTS as $host) {
            $this->assertMatchesRegularExpression('~^https://[a-z]+\.paymob\.com$~', $host);
        }
    }

    public function test_an_egyptian_callback_signed_with_another_secret_is_refused(): void
    {
        $store = $this->storeWithGateway();
        $tx = $this->giftCard($store);
        $this->pay($tx);
        $obj = ['id' => 1, 'success' => true, 'currency' => 'EGP', 'amount_cents' => 2500,
            'order' => ['id' => 'PO'.$tx->id, 'merchant_order_id' => (string) $tx->id]];

        $this->postJson('/webhook/paymob?hmac='.str_repeat('a', 128), ['type' => 'TRANSACTION', 'obj' => $obj])->assertStatus(401);

        $this->assertGiftCardNotSettled($store, $tx);
    }
}
