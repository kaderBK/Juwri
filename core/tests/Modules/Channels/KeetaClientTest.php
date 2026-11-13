<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Channels;

use Juwri\Modules\Channels\Services\Aggregators\Keeta\FakeKeetaClient;
use Juwri\Modules\Channels\Services\Aggregators\Keeta\KeetaSigner;
use Juwri\Modules\Channels\Services\Aggregators\Keeta\KeetaTokenManager;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * N2-P1 — Keeta client: HMAC signing vector, token refresh policy, Fake round-trip.
 */
class KeetaClientTest extends TestCase
{
    public function test_signature_matches_hand_computed_vector(): void
    {
        $secret = 'app_secret_123';
        $method = 'POST';
        $path = '/api/open/order/confirm';
        $query = ['b' => '2', 'a' => '1'];        // deliberately unsorted
        $body = '{"orderId":"K1"}';

        // Hand-compute: canonical = METHOD \n PATH \n sorted-query \n body
        $canonical = "POST\n/api/open/order/confirm\n" . http_build_query(['a' => '1', 'b' => '2']) . "\n" . $body;
        $expected = hash_hmac('sha256', $canonical, $secret);

        $this->assertSame($expected, KeetaSigner::sign($secret, $method, $path, $query, $body));
        $this->assertTrue(KeetaSigner::verify($secret, $method, $path, $query, $body, $expected));
        $this->assertTrue(KeetaSigner::verify($secret, $method, $path, $query, $body, 'sha256=' . $expected));
        $this->assertFalse(KeetaSigner::verify($secret, $method, $path, $query, $body, 'deadbeef'));
    }

    public function test_token_refresh_early_and_grace_and_rate_guard(): void
    {
        $now = Carbon::parse('2026-07-12 12:00:00');

        // Expires in 90 days → no refresh yet.
        $this->assertFalse(KeetaTokenManager::shouldRefresh($now->copy()->addDays(90), $now));
        // Expires in 3 days → within the 4-day early window → refresh.
        $this->assertTrue(KeetaTokenManager::shouldRefresh($now->copy()->addDays(3), $now));
        // No token → refresh.
        $this->assertTrue(KeetaTokenManager::shouldRefresh(null, $now));

        // Rate guard: last refresh 30s ago → blocked; 61s ago → allowed.
        $this->assertFalse(KeetaTokenManager::canRefresh($now->copy()->subSeconds(30), $now));
        $this->assertTrue(KeetaTokenManager::canRefresh($now->copy()->subSeconds(61), $now));
        $this->assertTrue(KeetaTokenManager::canRefresh(null, $now));

        // Grace: refreshed 30 min ago → old token still ok; 61 min → expired.
        $this->assertTrue(KeetaTokenManager::withinGrace($now->copy()->subMinutes(30), $now));
        $this->assertFalse(KeetaTokenManager::withinGrace($now->copy()->subMinutes(61), $now));
    }

    public function test_fake_client_records_calls(): void
    {
        FakeKeetaClient::reset();
        $c = new FakeKeetaClient();
        $c->merchantUpdate(['skus' => [['code' => 'A'], ['code' => 'B']]]);
        $c->confirmOrder('K1');
        $c->prepareOrder('K1');

        $this->assertSame(2, count(FakeKeetaClient::$lastMenu['skus']));
        $this->assertSame(['merchantUpdate', 'confirm', 'prepare'], array_column(FakeKeetaClient::$calls, 'op'));
        $this->assertTrue($c->ping('store-1'));
        $this->assertFalse($c->ping(''));
    }
}
