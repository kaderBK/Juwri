<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Services\Tlync;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * T-Lync (tlync.ly) — the TDSP gateway's HTTP surface, shared by the merchant
 * checkout (User\PaymentMethods\TdspController) and reseller BYOK billing
 * (Reseller\Billing\TlyncBilling, parity A5).
 *
 * Flow: initiate (Bearer token, form post) → redirect the payer to the returned
 * purchase URL → the gateway POSTs `backend_url` → the receiver RE-VERIFIES with
 * the receipt endpoint. The callback carries NO signature, so callers must never
 * settle from it (WI-17b): its body only names the custom_ref to look up, and
 * only receiptSettles() — a completed LYD receipt for our exact amount — pays.
 *
 * Transport errors are NOT caught here — each caller keeps its own logging and
 * user-facing fallback.
 */
class TlyncClient
{
    public const BASE_SANDBOX = 'https://uat-api.tlync.ly/api/v3/';

    public const BASE_LIVE = 'https://api.tlync.ly/api/v3/';

    public static function base(bool $sandbox): string
    {
        return $sandbox ? self::BASE_SANDBOX : self::BASE_LIVE;
    }

    /** T-Lync amounts are LYD with 3 decimals, sent as a plain string. */
    public static function amount(float|int|string $amount): string
    {
        return number_format((float) $amount, 3, '.', '');
    }

    /**
     * POST payment/initiate. $fields: id (store id), amount, phone, email,
     * backend_url, frontend_url, custom_ref.
     *
     * @param  array<string, string>  $fields
     */
    public function initiate(string $base, string $token, array $fields): Response
    {
        return Http::asForm()->withToken($token)->acceptJson()
            ->post($base.'payment/initiate', $fields);
    }

    /** POST receipt/transaction for one custom_ref. */
    public function receipt(string $base, string $token, string $storeId, string $customRef): Response
    {
        return Http::asForm()->withToken($token)->acceptJson()
            ->post($base.'receipt/transaction', [
                'store_id' => $storeId,
                'custom_ref' => $customRef,
            ]);
    }

    /** The redirect URL from a successful initiate response, else null. */
    public static function purchaseUrl(Response $response): ?string
    {
        $json = $response->json();
        if ($response->successful() && is_array($json) && ($json['result'] ?? null) === 'success' && ! empty($json['url'])) {
            return (string) $json['url'];
        }

        return null;
    }

    /** T-Lync settles Libyan dinars only; amounts carry 3 decimals (dirhams). */
    public const CURRENCY = 'LYD';

    public const DECIMALS = 3;

    /**
     * A COMPLETED receipt (WI-17b, TDSP Merchant Portal API docs), else null.
     *
     * POST receipt/transaction answers 200 {"result":"success","data":{amount,
     * currency, gateway_name, gateway_ref, date_time, reference, ...}} only for a
     * completed payment; 200 {"result":"incomplete"} for found-but-unpaid; 404
     * for an unknown ref; 401 for a bad token. So a receipt counts ONLY when
     * result === "success" (exactly) AND `data` is an object AND data.amount is
     * numeric AND data.currency is non-empty. A bare `reference`, a top-level
     * `payment_status` / `amount` / `currency`, or `notes_to_shop` never count.
     *
     * @return array{amount_minor: int, currency: string, reference: ?string, gateway_name: ?string, gateway_ref: ?string, custom_ref: ?string}|null
     */
    public static function completedReceipt(mixed $json): ?array
    {
        if (! is_array($json) || ($json['result'] ?? null) !== 'success') {
            return null;
        }
        $data = $json['data'] ?? null;
        if (! is_array($data) || $data === [] || array_is_list($data)) {
            return null; // must be a JSON object
        }
        $amount = $data['amount'] ?? null;
        $currency = $data['currency'] ?? null;
        if (! is_numeric($amount) || ! is_string($currency) || trim($currency) === '') {
            return null;
        }
        $minor = self::toMinor($amount);
        if ($minor === null) {
            return null;
        }
        $str = fn (string $k): ?string => is_scalar($data[$k] ?? null) && trim((string) $data[$k]) !== '' ? trim((string) $data[$k]) : null;

        return [
            'amount_minor' => $minor,
            'currency' => strtoupper(trim($currency)),
            'reference' => $str('reference'),
            'gateway_name' => $str('gateway_name'),
            'gateway_ref' => $str('gateway_ref'),
            'custom_ref' => $str('custom_ref'),
        ];
    }

    /**
     * Does this receipt settle OUR payment? Completed (see completedReceipt) AND
     * in LYD AND for exactly $expectedMinor dirhams (integer compare, no float
     * equality) AND — when the receipt names a custom_ref — for our ref.
     */
    public static function receiptSettles(mixed $json, int $expectedMinor, string $customRef): bool
    {
        $r = self::completedReceipt($json);

        return $r !== null
            && $r['currency'] === self::CURRENCY
            && $r['amount_minor'] === $expectedMinor
            && ($r['custom_ref'] === null || $r['custom_ref'] === $customRef);
    }

    /** Does a receipt say the payment completed? (Strict — see completedReceipt.) */
    public static function receiptIsPaid(mixed $json): bool
    {
        return self::completedReceipt($json) !== null;
    }

    /**
     * An LYD amount in integer dirhams, exact: "10.000" / "10" / 10 → 10000.
     * Negative, malformed, or finer than a dirham ("10.0005") → null, so it can
     * never equal an expected amount by rounding.
     */
    public static function toMinor(mixed $amount): ?int
    {
        if (is_int($amount)) {
            return $amount >= 0 ? $amount * 10 ** self::DECIMALS : null;
        }
        if (is_float($amount)) {
            if (! is_finite($amount) || $amount < 0) {
                return null;
            }
            $amount = rtrim(rtrim(sprintf('%.6F', $amount), '0'), '.');
        }
        if (! is_string($amount) || ! preg_match('/^\s*(\d+)(?:\.(\d+))?\s*$/', $amount, $m)) {
            return null;
        }
        $frac = $m[2] ?? '';
        if (strlen($frac) > self::DECIMALS && trim(substr($frac, self::DECIMALS), '0') !== '') {
            return null;
        }
        $int = ltrim($m[1], '0');
        if (strlen($int) > 12) {
            return null; // overflow guard — no real payment is a trillion dinars
        }

        return (int) ($int === '' ? '0' : $int) * 10 ** self::DECIMALS
            + (int) str_pad(substr($frac, 0, self::DECIMALS), self::DECIMALS, '0');
    }

    /** A stored transaction amount (decimal column) in LYD dirhams, via money_round(). */
    public static function transactionMinor(float|int|string|null $amount): int
    {
        // -1 (never a receipt amount) when the stored amount is negative / unparseable.
        return self::toMinor(number_format(money_round((float) $amount, self::DECIMALS), self::DECIMALS, '.', '')) ?? -1;
    }
}
