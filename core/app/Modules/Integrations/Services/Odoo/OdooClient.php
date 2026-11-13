<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Services\Odoo;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Juwri\Core\Services\SsrfGuard;

/**
 * P4-21: Odoo's external JSON-RPC API (`POST <url>/jsonrpc`, services `common` and `object` — the same calls on
 * Odoo Community and Enterprise, 16+). One client per store connection; the API key is sent only in the request
 * body to the store's own Odoo and never logged: errors carry Odoo's message, never the request.
 *
 * Where it may connect: {@see target()} (public https only, pinned; private hosts only from an exact allowlist).
 */
final class OdooClient
{
    private ?int $uid = null;

    private int $seq = 0;

    public function __construct(private readonly OdooConnection $conn) {}

    public static function urlAllowed(string $url): bool
    {
        return self::target($url) !== null;
    }

    /**
     * Where a call to $url may go, re-decided on EVERY call (DNS can change between saving and sending):
     *  - local / testing: any http(s) address (development, the test fake);
     *  - a public address: https only, every resolved IP public, and the connection pinned to the vetted IP
     *    (CURLOPT_RESOLVE) so a DNS answer that changes after the check (rebinding) cannot redirect it;
     *  - a private address: only when the host name, or every IP it resolves to, is listed exactly in
     *    `services.odoo.private_hosts` (ODOO_PRIVATE_HOSTS — a self-hoster's Odoo on their own LAN; never a wildcard
     *    or a range); http is accepted there; pinned the same way.
     * Null = refused.
     *
     * @return array{curl: array<int, mixed>}|null
     */
    public static function target(string $url): ?array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        if (app()->environment(['local', 'testing'])) {
            return ['curl' => []];
        }

        $allow = self::privateHosts();
        $ips = SsrfGuard::resolve($host);
        if ($ips === []) {
            return null; // cannot verify: refused
        }
        $listed = in_array($host, $allow, true) || array_diff(array_map('strtolower', $ips), $allow) === [];
        if ($listed) {
            $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

            return ['curl' => [CURLOPT_RESOLVE => [SsrfGuard::resolveEntry(['host' => $host, 'port' => $port, 'ip' => (string) $ips[0]])]]];
        }
        if ($scheme !== 'https') {
            return null; // the API key must never travel in clear to a non-listed host
        }
        $pin = SsrfGuard::pin($url);

        return $pin === null ? null : ['curl' => [CURLOPT_RESOLVE => [SsrfGuard::resolveEntry($pin)]]];
    }

    /** @return list<string> exact host names / IPs (wildcards and ranges are ignored) */
    public static function privateHosts(): array
    {
        $raw = config('services.odoo.private_hosts', '');
        $list = is_array($raw) ? $raw : explode(',', (string) $raw);

        return array_values(array_filter(array_map(fn ($h) => strtolower(trim((string) $h, " \t[]")), $list),
            fn (string $h) => $h !== '' && ! str_contains($h, '*') && ! str_contains($h, '/')));
    }

    /** The user id the API key signs in as (cached for this client). */
    public function uid(): int
    {
        if ($this->uid !== null) {
            return $this->uid;
        }
        $uid = $this->call('common', 'login', [$this->conn->database, $this->conn->username, $this->conn->apiKey]);
        if (! is_int($uid) || $uid <= 0) {
            throw new OdooError('Odoo refused the database, user or API key.', permanent: true);
        }

        return $this->uid = $uid;
    }

    /**
     * model.method(*args, **kwargs) as the connection's user.
     *
     * @param  list<mixed>  $args
     * @param  array<string, mixed>  $kwargs
     */
    public function execute(string $model, string $method, array $args = [], array $kwargs = []): mixed
    {
        return $this->call('object', 'execute_kw', [
            $this->conn->database, $this->uid(), $this->conn->apiKey, $model, $method, $args, (object) $kwargs,
        ]);
    }

    /**
     * @param  list<mixed>  $domain
     * @param  list<string>  $fields
     * @return list<array<string, mixed>>
     */
    public function searchRead(string $model, array $domain, array $fields, int $limit = 0): array
    {
        $rows = $this->execute($model, 'search_read', [$domain], ['fields' => $fields, 'limit' => $limit, 'context' => ['active_test' => false]]);

        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $context
     */
    public function create(string $model, array $values, array $context = []): int
    {
        $id = $this->execute($model, 'create', [$values], $context !== [] ? ['context' => $context] : []);
        if (is_array($id)) {
            $id = $id[0] ?? null;
        }
        if (! is_int($id) || $id <= 0) {
            throw new OdooError("Odoo did not return an id for the new {$model}.");
        }

        return $id;
    }

    /** @param  list<mixed>  $params */
    private function call(string $service, string $method, array $params): mixed
    {
        $target = self::target($this->conn->url);
        if ($target === null) {
            throw new OdooError('The Odoo address is not allowed (a public https address, or a private one listed in ODOO_PRIVATE_HOSTS).', permanent: true);
        }
        try {
            $res = Http::timeout(30)->withOptions($target + ['allow_redirects' => false])->acceptJson()->asJson()->post(rtrim($this->conn->url, '/').'/jsonrpc', [
                'jsonrpc' => '2.0', 'method' => 'call', 'id' => ++$this->seq,
                'params' => ['service' => $service, 'method' => $method, 'args' => $params],
            ]);
        } catch (ConnectionException) {
            throw new OdooError('Odoo could not be reached.');
        }
        if ($res->failed()) {
            throw new OdooError('Odoo answered HTTP '.$res->status().'.', permanent: in_array($res->status(), [401, 403, 404], true));
        }
        $body = $res->json();
        if (! is_array($body)) {
            throw new OdooError('Odoo answered something that is not JSON-RPC.');
        }
        if (isset($body['error'])) {
            $data = is_array($body['error']['data'] ?? null) ? $body['error']['data'] : [];
            $message = trim((string) ($data['message'] ?? $body['error']['message'] ?? 'error'));
            $name = (string) ($data['name'] ?? '');
            // Access / validation errors do not heal by retrying; anything else (a lock, a restart) may.
            $permanent = str_contains($name, 'AccessDenied') || str_contains($name, 'AccessError') || str_contains($name, 'ValidationError')
                || str_contains($name, 'UserError') || str_contains($name, 'MissingError');

            throw new OdooError('Odoo: '.mb_substr($message, 0, 300), permanent: $permanent);
        }

        return $body['result'] ?? null;
    }
}
