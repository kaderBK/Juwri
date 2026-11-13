<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Channels\Services\HungerStation;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Juwri\Modules\Channels\Models\HsEventLog;

/**
 * Real HungerStation Partner API client (P1). OAuth2 client-credentials: fetch +
 * cache an access token per tenant, then make authorized requests with retry/backoff.
 * Every call is logged to hs_event_log (secrets redacted). Uses the Laravel HTTP
 * client so tests fake it with Http::fake() — no live HS call in CI.
 *
 * BYOK: secrets come from the tenant's Credential (feature='hungerstation'); the
 * caller (factory) resolves them — this class never reads client input for ids.
 */
class HungerStationClient implements HungerStationClientInterface
{
    public function __construct(
        private array $creds,            // client_id, client_secret, base_url, auth_url
        private ?int $storeId = null,
    ) {}

    /** Cached OAuth2 access token (client-credentials). */
    public function token(): string
    {
        $clientId = (string) ($this->creds['client_id'] ?? '');
        if ($clientId === '') {
            throw new HungerStationException('HungerStation: missing client_id');
        }

        return Cache::remember('hs:token:'.md5($clientId), 3300, function () {
            $res = Http::asForm()->post($this->authUrl(), [
                'grant_type' => 'client_credentials',
                'client_id' => $this->creds['client_id'] ?? '',
                'client_secret' => $this->creds['client_secret'] ?? '',
            ]);

            if (! $res->successful() || ! $res->json('access_token')) {
                throw new HungerStationException('HungerStation: token request failed ('.$res->status().')');
            }

            return (string) $res->json('access_token');
        });
    }

    public function fetchOrder(string $chainId, string $orderId): array
    {
        return $this->request('get', "/v2/chains/{$chainId}/orders/{$orderId}", [], 'order.fetch', $orderId);
    }

    public function updateOrderStatus(string $chainId, string $orderId, string $status, array $extra = []): array
    {
        return $this->request('put', "/v2/chains/{$chainId}/orders/{$orderId}", array_merge(['status' => $status], $extra), 'order.status', $orderId);
    }

    public function listCatalog(string $chainId, string $vendorId): array
    {
        return $this->request('get', "/v2/chains/{$chainId}/vendors/{$vendorId}/catalog", [], 'catalog.list');
    }

    public function updateCatalog(string $chainId, string $vendorId, array $items): array
    {
        return $this->request('put', "/v2/chains/{$chainId}/vendors/{$vendorId}/catalog", ['items' => $items], 'catalog.update');
    }

    public function exportCatalog(string $chainId, string $vendorId): array
    {
        return $this->request('post', "/v2/chains/{$chainId}/vendors/{$vendorId}/catalog/export", [], 'catalog.export');
    }

    /**
     * Authorized request with retry/backoff + event logging. Throws on 4xx (client
     * error — don't retry) after logging; retries transient 5xx.
     */
    private function request(string $method, string $path, array $body, string $kind, ?string $orderId = null): array
    {
        $res = Http::baseUrl($this->baseUrl())
            ->withToken($this->token())
            ->acceptJson()
            ->retry(3, 200, throw: false)
            ->{$method}($path, $body);

        $ok = $res->successful();
        HsEventLog::record('out', $kind, [
            'store_id' => $this->storeId,
            'hs_order_id' => $orderId,
            'request' => ['method' => $method, 'path' => $path, 'body' => $body], // no secrets
            'response' => $res->json() ?: ['raw' => mb_substr((string) $res->body(), 0, 500)],
            'status_code' => $res->status(),
            'ok' => $ok,
        ]);

        if (! $ok) {
            throw new HungerStationException("HungerStation {$kind} failed ({$res->status()})");
        }

        return $res->json() ?: [];
    }

    private function baseUrl(): string
    {
        return rtrim((string) ($this->creds['base_url'] ?? config('services.hungerstation.base_url')), '/');
    }

    private function authUrl(): string
    {
        return (string) ($this->creds['auth_url'] ?? config('services.hungerstation.auth_url'));
    }
}
