<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Channels;

use Juwri\Modules\Channels\Services\HungerStation\HungerStationClient;
use Juwri\Modules\Channels\Services\HungerStation\HungerStationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * HungerStation P1 — OAuth2 client-credentials client: token fetch + cache, authorized
 * requests with retry/backoff, event logging, 4xx throws. Http::fake — no live calls.
 */
class HungerStationClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush(); // token cache must not bleed across tests (shared client_id key)
    }

    private function creds(): array
    {
        return [
            'client_id'     => 'cid',
            'client_secret' => 'sec',
            'base_url'      => 'https://hs.test',
            'auth_url'      => 'https://hs.test/oauth/token',
        ];
    }

    public function test_token_is_fetched_once_and_cached(): void
    {
        Http::fake([
            'hs.test/oauth/token' => Http::response(['access_token' => 'tok_1', 'expires_in' => 3600]),
            'hs.test/v2/*'        => Http::response(['ok' => true]),
        ]);

        $client = new HungerStationClient($this->creds(), 10);
        $client->fetchOrder('C1', 'O1');
        $client->fetchOrder('C1', 'O2');

        // token endpoint hit exactly once (cached), despite two API calls.
        $tokenCalls = 0;
        Http::assertSent(function ($req) use (&$tokenCalls) {
            if (str_contains($req->url(), 'oauth/token')) {
                $tokenCalls++;
            }
            return true;
        });
        $this->assertSame(1, $tokenCalls);
    }

    public function test_successful_request_logs_event(): void
    {
        Http::fake([
            'hs.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'hs.test/v2/*'        => Http::response(['status' => 'DISPATCHED', 'ok' => true]),
        ]);

        $client = new HungerStationClient($this->creds(), 10);
        $res = $client->updateOrderStatus('C1', 'O1', 'DISPATCHED');

        $this->assertTrue($res['ok']);
        $this->assertDatabaseHas('hungerstation_event_logs', ['direction' => 'out', 'kind' => 'order.status', 'hs_order_id' => 'O1', 'ok' => 1]);
    }

    public function test_4xx_throws_and_logs_failure(): void
    {
        Http::fake([
            'hs.test/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'hs.test/v2/*'        => Http::response(['error' => 'bad'], 422),
        ]);

        $client = new HungerStationClient($this->creds(), 10);

        try {
            $client->fetchOrder('C1', 'O1');
            $this->fail('expected HungerStationException');
        } catch (HungerStationException $e) {
            // expected
        }
        $this->assertDatabaseHas('hungerstation_event_logs', ['kind' => 'order.fetch', 'ok' => 0, 'status_code' => 422]);
    }

    public function test_token_failure_throws(): void
    {
        Http::fake(['hs.test/oauth/token' => Http::response(['error' => 'nope'], 401)]);

        $client = new HungerStationClient($this->creds(), 10);
        $this->expectException(HungerStationException::class);
        $client->fetchOrder('C1', 'O1');
    }
}
