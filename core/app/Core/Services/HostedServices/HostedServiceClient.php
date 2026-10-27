<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services\HostedServices;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Juwri\Core\Contracts\Editions\InstallLicenceProof;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\Tenancy\TenantContext;
use Juwri\Core\Settings\PlatformSettings;
use RuntimeException;

/**
 * This installation's side of the juwri-hosted services (owner 2026-10-05): its identity and signed calls.
 *
 * - No phone-home: nothing here runs on its own. {@see register()} is called only when the owner connects a juwri
 *   service (artisan juwri:services:connect today; the service's own settings page when one ships), and
 *   {@see send()} only when a feature the owner turned on calls its service.
 * - Identity: a licensed installation (Chain / Enterprise) connects with its licence ID + the SHA-256 of its licence
 *   file ({@see InstallLicenceProof}); a Community installation gets a free Community install ID. Either way the
 *   service returns a secret; both are kept in the platform settings (the secret encrypted at rest).
 * - Every call is signed ({@see RequestSignature}) and names the store (and branch) it is for, plus this
 *   installation's own store/branch counts. The service decides the edition and the limits itself; the counts it
 *   receives are only compared with what it has seen (a mismatch is flagged, never used to allow anything).
 */
final class HostedServiceClient
{
    public const INSTALL_ID = 'juwri_services_install_id';

    public const INSTALL_SECRET = 'juwri_services_install_secret';

    public const REGISTER_PATH = '/api/hosted/v1/installs';

    public function __construct(private readonly InstallLicenceProof $licence) {}

    public static function baseUrl(): string
    {
        return rtrim((string) config('hosted_services.url', 'https://services.juwri.com'), '/');
    }

    public function connected(): bool
    {
        return $this->installId() !== null && $this->secret() !== null;
    }

    public function installId(): ?string
    {
        $id = PlatformSettings::string(self::INSTALL_ID);

        return $id !== '' ? $id : null;
    }

    /** Is this partner webhook genuinely forwarded by the juwri relay for this installation? (the secret stays here) */
    public function verifyRelayed(string $partner, string $timestamp, string $body, string $signature): bool
    {
        $secret = $this->secret();

        return $secret !== null && RelaySignature::verify($secret, $partner, $timestamp, $body, $signature, time());
    }

    private function secret(): ?string
    {
        $secret = PlatformSettings::string(self::INSTALL_SECRET);

        return $secret !== '' ? $secret : null;
    }

    /**
     * Connect this installation: ask the service for an install identity and keep it. A licensed installation sends
     * its licence ID + licence-file hash; the service checks them against the issued-licence register. Connecting
     * again replaces the stored secret (the old one stops working on the service side).
     *
     * @return array{install_id: string, edition: string}
     */
    public function register(): array
    {
        $licenceId = $this->licence->licenceId();
        $body = $licenceId !== null
            ? ['kind' => 'licence', 'licence_id' => $licenceId, 'licence_sha256' => (string) $this->licence->licenceFileSha256()]
            : ['kind' => 'community'];

        $response = Http::acceptJson()->asJson()->timeout($this->timeout())
            ->post(self::baseUrl().self::REGISTER_PATH, $body);
        $data = $response->json();
        if (! $response->successful() || ! is_array($data) || ! is_string($data['install_id'] ?? null) || ! is_string($data['secret'] ?? null)) {
            $message = is_array($data) && is_string($data['message'] ?? null) ? $data['message'] : 'HTTP '.$response->status();

            throw new RuntimeException('juwri services did not connect this installation: '.$message);
        }
        if (preg_match(RequestSignature::REF_PATTERN, $data['install_id']) !== 1 || strlen($data['secret']) < 32) {
            throw new RuntimeException('juwri services returned an install identity in an unexpected format.');
        }
        PlatformSettings::set(self::INSTALL_ID, $data['install_id']);
        PlatformSettings::set(self::INSTALL_SECRET, $data['secret']);

        return ['install_id' => $data['install_id'], 'edition' => (string) ($data['edition'] ?? '')];
    }

    /**
     * One signed call to a juwri-hosted service, for $store (and $branchId when the call is for one branch).
     *
     * @param  array<string, mixed>  $payload  JSON body (ignored for GET)
     */
    public function send(string $method, string $path, array $payload = [], ?Store $store = null, ?int $branchId = null): Response
    {
        $installId = $this->installId();
        $secret = $this->secret();
        if ($installId === null || $secret === null) {
            throw new RuntimeException('This installation is not connected to juwri services.');
        }
        $method = strtoupper($method);
        $path = '/'.ltrim($path, '/');
        $body = $method === 'GET' || $payload === [] ? '' : (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $headers = RequestSignature::headers($installId, $secret, $method, $path, $body, $this->context($store, $branchId), time(), RequestSignature::nonce());

        $request = Http::acceptJson()->timeout($this->timeout())->withHeaders($headers);
        if ($body !== '') {
            $request = $request->withBody($body, 'application/json');
        }

        return $request->send($method, self::baseUrl().$path);
    }

    /**
     * The signed context of a call: the store (id + type), the branch, and this installation's own store and branch
     * counts (the whole installation: a Community installation runs one business, and the services count per
     * installation too).
     *
     * @return array<string, string|int|null>
     */
    public function context(?Store $store, ?int $branchId = null): array
    {
        return TenantContext::bypass(fn (): array => [
            RequestSignature::STORE => $store?->getKey(),
            RequestSignature::STORE_TYPE => $store !== null ? (string) ($store->store_type ?: '') : null,
            RequestSignature::BRANCH => $branchId,
            RequestSignature::REPORTED_STORES => Store::query()->withoutGlobalScopes()->count(),
            RequestSignature::REPORTED_BRANCHES => Branch::query()->withoutGlobalScopes()->count(),
        ]);
    }

    private function timeout(): int
    {
        return max(1, (int) config('hosted_services.timeout', 15));
    }
}
