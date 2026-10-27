<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments\MenaAdapters;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\PaymobController;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Gateways\PaymobAdapter;

/** P1-23 part 5 — Paymob on the adapter family ({@see MenaAdapterTestCase}). */
class PaymobAdapterTest extends MenaAdapterTestCase
{
    private int $event = 0;

    protected function gateway(): string
    {
        return 'paymob';
    }

    protected function controller(): string
    {
        return PaymobController::class;
    }

    protected function storeCreds(): array
    {
        return ['secret_key' => 'sk_store', 'public_key' => 'pk_store', 'integration_id' => '5', 'hmac_secret' => 'hmac_store'];
    }

    protected function platformCreds(): array
    {
        return ['settings.paymob_secret_key' => 'sk_platform', 'settings.paymob_public_key' => 'pk_platform', 'settings.paymob_integration_id' => '6', 'settings.paymob_hmac_secret' => 'hmac_platform'];
    }

    protected function sentWith(ClientRequest $request, bool $store): bool
    {
        return $request->hasHeader('Authorization', 'Token '.($store ? 'sk_store' : 'sk_platform'));
    }

    protected function answerInitiate(ClientRequest $request): mixed
    {
        return str_contains($request->url(), '/v1/intention/')
            ? Http::response(['client_secret' => 'cs_'.$request['special_reference'], 'intention_order_id' => 'PO'.$request['special_reference']])
            : null;
    }

    protected function startedByOldController(Transaction $transaction): void
    {
        $transaction->forceFill(['details' => ['paymob_order_id' => 'PO'.$transaction->id]])->save();
    }

    protected function confirm(Transaction $transaction, string $amount, string $currency, bool $store): TestResponse
    {
        $obj = [
            'amount_cents' => (int) round(((float) $amount) * 100), 'created_at' => '2026-10-04T10:00:00', 'currency' => $currency,
            'error_occured' => false, 'has_parent_transaction' => false, 'id' => 9000 + (++$this->event), 'integration_id' => 5,
            'is_3d_secure' => true, 'is_auth' => false, 'is_capture' => false, 'is_refunded' => false, 'is_standalone_payment' => true,
            'is_voided' => false, 'order' => ['id' => 'PO'.$transaction->id, 'merchant_order_id' => (string) $transaction->id],
            'owner' => 1, 'pending' => false, 'source_data' => ['pan' => '2346', 'sub_type' => 'MasterCard', 'type' => 'card'], 'success' => true,
        ];
        $concat = '';
        foreach (PaymobAdapter::HMAC_FIELDS as $field) {
            $v = data_get($obj, $field);
            $concat .= is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
        }
        $hmac = hash_hmac('sha512', $concat, $store ? 'hmac_store' : 'hmac_platform');

        return $this->postJson('/webhook/paymob?hmac='.$hmac, ['type' => 'TRANSACTION', 'obj' => $obj]);
    }
}
