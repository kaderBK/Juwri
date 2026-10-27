<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\HostedServices;

/**
 * Self-hosted installations: asks the juwri update server (hosted.gate:updates) for a newer release and downloads its
 * package through a signed call, checking the SHA-256 before keeping it. Installing it stays the owner's step
 * (backup, unpack, migrate). A held installation (past its licensed limit) or a refused licence gets the service's
 * answer and downloads nothing; the installation keeps running its current version.
 */
final class HostedUpdateClient
{
    public function __construct(private readonly HostedServiceClient $client) {}

    /** @return array<string, mixed> the server's answer, or ['code' => …, 'message' => …] when refused */
    public function check(string $current, string $channel = 'stable'): array
    {
        if (! $this->client->connected()) {
            return ['code' => 'not_connected', 'message' => 'Connect this installation first (php artisan juwri:services:connect).'];
        }
        $res = $this->client->send('GET', '/api/hosted/v1/updates/latest?'.http_build_query(['channel' => $channel === 'beta' ? 'beta' : 'stable', 'current' => $current]));

        return $res->successful() ? (array) $res->json() : ['code' => (string) ($res->json('code') ?? 'unavailable'), 'message' => (string) ($res->json('message') ?? '')];
    }

    /**
     * Download the newer release into $dir: the file path, null when already up to date, or the refusal.
     *
     * @return string|array{code: string, message: string}|null
     */
    public function download(string $current, string $dir, string $channel = 'stable'): string|array|null
    {
        $latest = $this->check($current, $channel);
        if (isset($latest['code'])) {
            return ['code' => (string) $latest['code'], 'message' => (string) ($latest['message'] ?? '')];
        }
        if (($latest['up_to_date'] ?? true) === true) {
            return null;
        }
        $parts = parse_url((string) $latest['url']);
        $file = $this->client->send('GET', ($parts['path'] ?? '').(isset($parts['query']) ? '?'.$parts['query'] : ''));
        if (! $file->successful()) {
            return ['code' => (string) ($file->json('code') ?? 'download_failed'), 'message' => (string) ($file->json('message') ?? '')];
        }
        $body = $file->body();
        if (! hash_equals((string) $latest['sha256'], hash('sha256', $body))) {
            return ['code' => 'checksum_mismatch', 'message' => 'The package does not match its checksum; nothing was kept.'];
        }
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $target = rtrim($dir, '/').'/juwri-'.preg_replace('/[^0-9A-Za-z.+-]/', '', (string) $latest['version']).'.zip';
        file_put_contents($target, $body);

        return $target;
    }
}
