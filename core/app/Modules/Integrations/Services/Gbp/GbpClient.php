<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Services\Gbp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * google-partner Lane A — REAL Google Business Profile client (federated APIs).
 *
 * Built by {@see GbpClientFactory} ONLY when config('services.gbp.driver')==='real',
 * a stored access_token exists, and this class is present — otherwise the Fake is used
 * (dark discipline). Every call authenticates through the ONE token choke point
 * {@see GbpTokenService::accessTokenFor()} so an expired token is transparently
 * refreshed; when that returns null (unconfigured / refresh failed) the call fails soft
 * (reads → [], writes → throw so the queued job records sync_error). Nothing here logs
 * a token.
 *
 * Endpoints (federated Business Profile surface):
 *  - Accounts:   mybusinessaccountmanagement.googleapis.com/v1/accounts
 *  - Locations:  mybusinessbusinessinformation.googleapis.com/v1/{account}/locations
 *  - PlaceActions: mybusinessplaceactions.googleapis.com/v1/{location}/placeActionLinks
 *  - FoodMenus:  mybusiness.googleapis.com/v4/{account}/locations/{loc}/foodMenus (v4)
 */
class GbpClient implements GbpClientInterface
{
    private const ACCOUNTS_BASE = 'https://mybusinessaccountmanagement.googleapis.com/v1';

    private const INFO_BASE = 'https://mybusinessbusinessinformation.googleapis.com/v1';

    private const ACTIONS_BASE = 'https://mybusinessplaceactions.googleapis.com/v1';

    private const V4_BASE = 'https://mybusiness.googleapis.com/v4';

    private ?string $accountId;

    /**
     * @param  array<string,mixed>  $creds  Credential::for('gbp',$rid) secrets
     */
    public function __construct(private array $creds, private ?int $storeId = null)
    {
        $this->accountId = isset($creds['account_id']) ? (string) $creds['account_id'] : null;
    }

    // ---- Place action links (v1) ----

    /** @return array<int, array<string,mixed>> */
    public function listActionLinks(string $locationId): array
    {
        $res = $this->get(self::ACTIONS_BASE.'/'.$this->locationName($locationId).'/placeActionLinks');

        return $res['placeActionLinks'] ?? [];
    }

    /**
     * @param  array<string,mixed>  $body
     * @return array<string,mixed>
     */
    public function createActionLink(string $locationId, array $body): array
    {
        return $this->post(
            self::ACTIONS_BASE.'/'.$this->locationName($locationId).'/placeActionLinks',
            $body
        ) ?? [];
    }

    /**
     * @param  array<string,mixed>  $body
     * @return array<string,mixed>
     */
    public function patchActionLink(string $name, array $body): array
    {
        $mask = implode(',', array_keys($body));

        return $this->patch(self::ACTIONS_BASE.'/'.ltrim($name, '/'), $body, $mask) ?? [];
    }

    // ---- Connect surface ----

    public function listAccounts(): array
    {
        $res = $this->get(self::ACCOUNTS_BASE.'/accounts');

        return $res['accounts'] ?? [];
    }

    public function listLocations(string $account): array
    {
        $res = $this->get(self::INFO_BASE.'/'.ltrim($account, '/').'/locations', [
            'readMask' => 'name,title,storefrontAddress,phoneNumbers',
            'pageSize' => 100,
        ]);

        return $res['locations'] ?? [];
    }

    // ---- Food menus (v4) ----

    public function getFoodMenus(string $locationId): array
    {
        return $this->get(self::V4_BASE.'/'.$this->foodMenusName($locationId)) ?? [];
    }

    public function updateFoodMenus(string $locationId, array $foodMenus): array
    {
        $res = $this->patch(
            self::V4_BASE.'/'.$this->foodMenusName($locationId),
            $foodMenus,
            'menus'
        );

        if ($res === null) {
            throw new \RuntimeException('GBP updateFoodMenus failed');
        }

        return $res;
    }

    // ---- Resource-name helpers ----

    /** Normalize a location id/name to `locations/{id}` for the v1 surfaces. */
    private function locationName(string $locationId): string
    {
        if (str_contains($locationId, 'locations/')) {
            // e.g. 'accounts/1/locations/2' → 'locations/2' for the placeactions surface.
            $pos = strrpos($locationId, 'locations/');

            return substr($locationId, $pos);
        }

        return 'locations/'.$locationId;
    }

    /** Build the v4 FoodMenus resource name `accounts/{acc}/locations/{loc}/foodMenus`. */
    private function foodMenusName(string $locationId): string
    {
        if (str_contains($locationId, 'accounts/') && str_contains($locationId, 'locations/')) {
            return rtrim($locationId, '/').'/foodMenus';
        }

        $loc = str_contains($locationId, 'locations/')
            ? substr($locationId, strrpos($locationId, 'locations/') + strlen('locations/'))
            : $locationId;
        $acc = $this->accountId ?: 'accounts';

        $acc = str_starts_with($acc, 'accounts/') ? $acc : 'accounts/'.$acc;

        return "{$acc}/locations/{$loc}/foodMenus";
    }

    // ---- Authenticated transport (all fail soft; null on no-token/failure) ----

    private function token(): ?string
    {
        return $this->storeId
            ? GbpTokenService::accessTokenFor($this->storeId)
            : (! empty($this->creds['access_token']) ? (string) $this->creds['access_token'] : null);
    }

    /**
     * @param  array<string,mixed>  $query
     * @return array<string,mixed>|null
     */
    private function get(string $url, array $query = []): ?array
    {
        $token = $this->token();
        if ($token === null) {
            return null;
        }
        try {
            $res = Http::withToken($token)->acceptJson()->timeout(20)->get($url, $query);
            if ($res->failed()) {
                Log::info('[gbp] GET failed', ['status' => $res->status()]);

                return null;
            }

            return (array) $res->json();
        } catch (\Throwable $e) {
            Log::info('[gbp] GET transport error');

            return null;
        }
    }

    /**
     * @param  array<string,mixed>  $body
     * @return array<string,mixed>|null
     */
    private function post(string $url, array $body): ?array
    {
        $token = $this->token();
        if ($token === null) {
            return null;
        }
        try {
            $res = Http::withToken($token)->acceptJson()->timeout(20)->post($url, $body);
            if ($res->failed()) {
                Log::info('[gbp] POST failed', ['status' => $res->status()]);

                return null;
            }

            return (array) $res->json();
        } catch (\Throwable $e) {
            Log::info('[gbp] POST transport error');

            return null;
        }
    }

    /**
     * @param  array<string,mixed>  $body
     * @return array<string,mixed>|null
     */
    private function patch(string $url, array $body, ?string $updateMask = null): ?array
    {
        $token = $this->token();
        if ($token === null) {
            return null;
        }
        $full = $updateMask !== null ? $url.'?updateMask='.rawurlencode($updateMask) : $url;
        try {
            $res = Http::withToken($token)->acceptJson()->timeout(20)->patch($full, $body);
            if ($res->failed()) {
                Log::info('[gbp] PATCH failed', ['status' => $res->status()]);

                return null;
            }

            return (array) $res->json();
        } catch (\Throwable $e) {
            Log::info('[gbp] PATCH transport error');

            return null;
        }
    }
}
