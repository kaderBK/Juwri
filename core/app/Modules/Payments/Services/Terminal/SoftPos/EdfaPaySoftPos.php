<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Terminal\SoftPos;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Juwri\Modules\Payments\Models\TerminalAccount;

/**
 * EdfaPay SoftPOS with the store's own merchant account: bearer API key; our client UUID is the order_id (EdfaPay's
 * duplicate guard). Callbacks are signed over the raw body: X-EdfaPay-Signature = hex(HMAC-SHA256(callback secret, body)).
 * The exact EdfaPay SoftPOS signature format is confirmed at onboarding (config/softpos.php header comment).
 * Credentials: api_key, callback_secret.
 */
final class EdfaPaySoftPos extends HttpSoftPosDriver
{
    public const SIGNATURE_HEADER = 'X-EdfaPay-Signature';

    public function key(): string
    {
        return 'edfapay_softpos';
    }

    protected function configKey(): string
    {
        return 'edfapay';
    }

    protected function client(TerminalAccount $account): PendingRequest
    {
        return Http::withToken((string) (((array) $account->credentials)['api_key'] ?? ''))->acceptJson()->timeout(20);
    }

    protected function createBody(TerminalAccount $account, string $clientUuid, int $amountMinor, string $currency): array
    {
        return ['order_id' => $clientUuid, 'order_amount' => self::major($amountMinor, $currency), 'order_currency' => $currency, 'merchant_id' => $account->merchant_id];
    }

    protected function normalise(array $body): array
    {
        return array_filter(['reference' => isset($body['trans_id']) ? (string) $body['trans_id'] : null, 'state' => self::state((string) ($body['status'] ?? $body['result'] ?? '')),
            'masked_pan' => isset($body['card']) ? (string) $body['card'] : null, 'scheme' => isset($body['card_brand']) ? (string) $body['card_brand'] : null,
            'auth_code' => isset($body['auth_code']) ? (string) $body['auth_code'] : null], fn ($v) => $v !== null);
    }

    public function verifyCallback(TerminalAccount $account, Request $request): bool
    {
        $secret = (string) (((array) $account->credentials)['callback_secret'] ?? '');
        $given = strtolower((string) $request->header(self::SIGNATURE_HEADER, ''));

        return $secret !== '' && $given !== '' && hash_equals(hash_hmac('sha256', (string) $request->getContent(), $secret), $given);
    }

    public function callbackReference(Request $request): ?string
    {
        $id = $request->input('trans_id');

        return is_scalar($id) && (string) $id !== '' ? (string) $id : null;
    }
}
