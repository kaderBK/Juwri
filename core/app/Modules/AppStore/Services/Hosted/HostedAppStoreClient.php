<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\AppStore\Services\Hosted;

use Juwri\Core\Models\Store;
use Juwri\Core\Services\HostedServices\HostedServiceClient;

/**
 * Self-hosted installations: the juwri App Store / theme marketplace (hosted.gate:appstore) for one installing store.
 * Lists the catalogue, asks for a short-lived signed download link (a paid item needs a purchase for this store) and
 * fetches the package through a signed call for the same store, then checks its SHA-256 before keeping it. A refusal
 * (store past the edition's limit, not bought, link expired) downloads nothing; the store keeps working.
 */
final class HostedAppStoreClient
{
    public function __construct(private readonly HostedServiceClient $client) {}

    /** @return list<array<string, mixed>>|null null = refused / unreachable */
    public function items(int $storeId): ?array
    {
        $store = Store::query()->find($storeId);
        if ($store === null || ! $this->client->connected()) {
            return null;
        }
        $res = $this->client->send('GET', '/api/hosted/v1/appstore/items', [], $store);

        return $res->successful() ? array_values((array) $res->json('items')) : null;
    }

    /**
     * Download into $dir; returns the file path, or ['code' => …, 'message' => …] when refused.
     *
     * @return string|array{code: string, message: string}
     */
    public function download(string $slug, int $storeId, string $dir): string|array
    {
        $store = Store::query()->find($storeId);
        if ($store === null || ! $this->client->connected() || preg_match('/^[a-z0-9-]{1,80}$/', $slug) !== 1) {
            return ['code' => 'not_connected', 'message' => 'Connect this installation and name a valid item and store.'];
        }
        $link = $this->client->send('POST', '/api/hosted/v1/appstore/items/'.$slug.'/download', [], $store);
        if (! $link->successful()) {
            return ['code' => (string) $link->json('code'), 'message' => (string) $link->json('message')];
        }
        $url = (string) $link->json('url');
        $parts = parse_url($url);
        $path = ($parts['path'] ?? '').(isset($parts['query']) ? '?'.$parts['query'] : '');
        $file = $this->client->send('GET', $path, [], $store);
        if (! $file->successful()) {
            return ['code' => (string) ($file->json('code') ?? 'download_failed'), 'message' => (string) ($file->json('message') ?? '')];
        }
        $body = $file->body();
        if (! hash_equals((string) $link->json('sha256'), hash('sha256', $body))) {
            return ['code' => 'checksum_mismatch', 'message' => 'The package does not match its checksum; nothing was kept.'];
        }
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $target = rtrim($dir, '/').'/'.$slug.'-'.preg_replace('/[^0-9A-Za-z.+-]/', '', (string) $link->json('version')).'.zip';
        file_put_contents($target, $body);

        return $target;
    }
}
