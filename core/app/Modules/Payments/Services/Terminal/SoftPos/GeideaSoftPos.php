<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Terminal\SoftPos;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Juwri\Modules\Payments\Models\TerminalAccount;
use Juwri\Modules\Payments\Support\GatewayMinorUnits;

/**
 * Geidea SoftPOS with the store's own merchant account: Basic auth (merchant public key : API password); our client UUID
 * is the merchantReferenceId (Geidea's own duplicate guard). Callbacks carry Geidea's documented signature:
 * base64(HMAC-SHA256(API password, publicKey + amount + currency + orderId + status + merchantReferenceId + timeStamp)).
 * Credentials: public_key, api_password.
 */
final class GeideaSoftPos extends HttpSoftPosDriver
{
    public function key(): string
    {
        return 'geidea_softpos';
    }

    protected function configKey(): string
    {
        return 'geidea';
    }

    protected function client(TerminalAccount $account): PendingRequest
    {
        $c = (array) $account->credentials;

        return Http::withBasicAuth((string) ($c['public_key'] ?? ''), (string) ($c['api_password'] ?? ''))->acceptJson()->timeout(20);
    }

    protected function createBody(TerminalAccount $account, string $clientUuid, int $amountMinor, string $currency): array
    {
        return ['amount' => self::major($amountMinor, $currency), 'currency' => $currency, 'merchantReferenceId' => $clientUuid, 'merchantId' => $account->merchant_id];
    }

    protected function normalise(array $body): array
    {
        $o = (array) ($body['order'] ?? $body);
        $card = (array) ($o['paymentMethod'] ?? []);

        return array_filter(['reference' => isset($o['orderId']) ? (string) $o['orderId'] : null, 'state' => self::state((string) ($o['status'] ?? '')),
            'masked_pan' => isset($card['maskedCardNumber']) ? (string) $card['maskedCardNumber'] : null, 'scheme' => isset($card['brand']) ? (string) $card['brand'] : null,
            'auth_code' => isset($o['authorizationCode']) ? (string) $o['authorizationCode'] : null], fn ($v) => $v !== null);
    }

    public function verifyCallback(TerminalAccount $account, Request $request): bool
    {
        $c = (array) $account->credentials;
        $o = (array) $request->input('order', []);
        $given = (string) $request->input('signature', '');
        $secret = (string) ($c['api_password'] ?? '');
        if ($given === '' || $secret === '' || ($o['orderId'] ?? '') === '') {
            return false;
        }
        // The HMAC is over the amount at Geidea's decimals (2 max, fewer for a 0-decimal currency).
        $amount = number_format((float) ($o['amount'] ?? 0), GatewayMinorUnits::gatewayDecimals('geidea', (string) ($o['currency'] ?? '')), '.', '');
        $msg = (string) ($c['public_key'] ?? '').$amount.(string) ($o['currency'] ?? '').(string) $o['orderId'].(string) ($o['status'] ?? '')
            .(string) ($o['merchantReferenceId'] ?? '').(string) $request->input('timeStamp', '');

        return hash_equals(base64_encode(hash_hmac('sha256', $msg, $secret, true)), $given);
    }

    public function callbackReference(Request $request): ?string
    {
        $id = $request->input('order.orderId');

        return is_scalar($id) && (string) $id !== '' ? (string) $id : null;
    }
}
