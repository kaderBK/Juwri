<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support;

/**
 * MoR-RUN F4 — signed webhook fixtures for the merchant-of-record gateways.
 *
 * No sandbox credentials exist in this environment, so these fixtures are the
 * e2e substitute: they produce byte-exact request bodies plus the signature
 * header each provider would send, letting webhook tests exercise the REAL
 * verification code path (raw body, HMAC, timing-safe compare).
 */
class MorWebhookFixtures
{
    /**
     * Paddle Billing signs `ts:rawBody` with HMAC-SHA256 and sends
     * `Paddle-Signature: ts=<unix>;h1=<hex>`.
     *
     * @return array{body: string, headers: array<string, string>}
     */
    public static function paddleSigned(array $payload, string $secret, ?int $ts = null): array
    {
        $body = json_encode($payload);
        $ts = $ts ?? time();
        $h1 = hash_hmac('sha256', $ts.':'.$body, $secret);

        return [
            'body' => $body,
            'headers' => ['Paddle-Signature' => "ts={$ts};h1={$h1}"],
        ];
    }

    /** A Paddle transaction.completed event carrying our metadata. */
    public static function paddleTransactionCompleted(array $customData, string $txnId = 'txn_01abc', ?string $subscriptionId = null): array
    {
        return [
            'event_id' => 'evt_01'.substr(md5($txnId), 0, 10),
            'event_type' => 'transaction.completed',
            'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'data' => [
                'id' => $txnId,
                'status' => 'completed',
                'custom_data' => $customData,
                'subscription_id' => $subscriptionId,
                'details' => [
                    'totals' => ['total' => '1500', 'tax' => '250', 'currency_code' => 'USD'],
                ],
            ],
        ];
    }

    /**
     * Lemon Squeezy signs the raw body with HMAC-SHA256 hex and sends it as
     * `X-Signature`.
     *
     * @return array{body: string, headers: array<string, string>}
     */
    public static function lemonSqueezySigned(array $payload, string $secret): array
    {
        $body = json_encode($payload);

        return [
            'body' => $body,
            'headers' => ['X-Signature' => hash_hmac('sha256', $body, $secret)],
        ];
    }

    /** A Lemon Squeezy event envelope carrying our metadata in meta.custom_data. */
    public static function lemonSqueezyEvent(string $eventName, array $customData, string $dataId = '1', array $attributes = []): array
    {
        return [
            'meta' => [
                'event_name' => $eventName,
                'custom_data' => $customData,
            ],
            'data' => [
                'type' => str_contains($eventName, 'subscription') ? 'subscriptions' : 'orders',
                'id' => $dataId,
                'attributes' => array_merge([
                    'status' => 'paid',
                    'total' => 1500,
                    'tax' => 250,
                    'currency' => 'USD',
                ], $attributes),
            ],
        ];
    }
}
