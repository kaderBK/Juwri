<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Auth\Services\Social;

use Illuminate\Support\Facades\Config;
use Juwri\Core\Models\Credential;
use Juwri\Modules\Auth\Contracts\SocialCredentials;

/**
 * FEATURES-5 Track A — resolves per-restaurant customer social-login credentials
 * from the Credential vault (feature='social', provider='google|facebook|apple').
 *
 * Resolution order (mirrors AiProviderFactory):
 *   merchant BYOK row (store_id = X)  →  platform default row (store_id NULL)
 *
 * Enable model:
 *   - Platform row `active` flag = provider globally available (superadmin switch).
 *   - Merchant row secrets['enabled'] === false = owner has hidden the button for
 *     their restaurant even though keys exist. Absent/true = shown.
 *   - A provider shows on a theme iff (platform enabled) AND (usable keys exist:
 *     merchant OR platform) AND (owner hasn't disabled it).
 */
class SocialCredentialResolver implements SocialCredentials
{
    /** @var array<string, ?Credential> rows read this request (a storefront page asks several times; was 6+ identical queries) */
    private array $rowMemo = [];

    // PROVIDERS (the providers customers may sign in with) is declared on the SocialCredentials contract (P1-08).

    /**
     * The raw effective secrets used to CONFIGURE the driver: merchant keys when
     * present, else platform keys. Returns null when neither side has keys.
     */
    public function secretsFor(string $provider, ?int $storeId = null): ?array
    {
        if (! in_array($provider, self::PROVIDERS, true)) {
            return null;
        }

        $merchant = $this->merchantRow($provider, $storeId);
        if ($merchant && $this->hasKeys($provider, $merchant->secrets ?? [])) {
            return $merchant->secrets;
        }

        $platform = $this->platformRow($provider);
        if ($platform && $this->hasKeys($provider, $platform->secrets ?? [])) {
            return $platform->secrets;
        }

        return null;
    }

    /**
     * Whether the provider button should render for this restaurant.
     */
    public function isEnabled(string $provider, ?int $storeId = null): bool
    {
        if (! in_array($provider, self::PROVIDERS, true)) {
            return false;
        }

        // Superadmin global switch: platform row must exist AND be active.
        $platform = $this->platformRow($provider);
        if (! $platform || ! $platform->active) {
            return false;
        }

        // Owner explicit off-switch.
        $merchant = $this->merchantRow($provider, $storeId);
        if ($merchant && is_array($merchant->secrets)
            && array_key_exists('enabled', $merchant->secrets)
            && $merchant->secrets['enabled'] === false) {
            return false;
        }

        // Usable keys must exist on one side.
        return $this->secretsFor($provider, $storeId) !== null;
    }

    /**
     * The list of provider slugs enabled for a restaurant (for the theme partial).
     *
     * @return string[]
     */
    public function enabledProviders(?int $storeId = null): array
    {
        return array_values(array_filter(
            self::PROVIDERS,
            fn ($p) => $this->isEnabled($p, $storeId)
        ));
    }

    /**
     * Inject the resolved credentials into config('services.<provider>') at
     * runtime so Socialite::driver() picks them up. Returns true when configured.
     */
    public function configure(string $provider, ?int $storeId, string $redirectUrl): bool
    {
        $secrets = $this->secretsFor($provider, $storeId);
        if (! $secrets) {
            return false;
        }

        if ($provider === 'apple') {
            $clientId = $secrets['client_id'] ?? null;
            $clientSecret = AppleClientSecret::generate($secrets);
            if (! $clientId || ! $clientSecret) {
                return false;
            }
            Config::set('services.apple', [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'redirect' => $redirectUrl,
            ]);

            return true;
        }

        $clientId = $secrets['client_id'] ?? null;
        $clientSecret = $secrets['client_secret'] ?? null;
        if (! $clientId || ! $clientSecret) {
            return false;
        }

        Config::set("services.$provider", [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect' => $redirectUrl,
        ]);

        return true;
    }

    /** Whether a secrets array carries the required client keys for a provider. */
    protected function hasKeys(string $provider, array $secrets): bool
    {
        if ($provider === 'apple') {
            return ! empty($secrets['client_id'])
                && ! empty($secrets['team_id'])
                && ! empty($secrets['key_id'])
                && ! empty($secrets['private_key']);
        }

        return ! empty($secrets['client_id']) && ! empty($secrets['client_secret']);
    }

    protected function merchantRow(string $provider, ?int $storeId): ?Credential
    {
        if (! $storeId) {
            return null;
        }

        return $this->memo("m:$storeId:$provider", fn () => Credential::where('store_id', $storeId)
            ->where('feature', 'social')
            ->where('provider', $provider)
            ->first());
    }

    protected function platformRow(string $provider): ?Credential
    {
        return $this->memo("p:$provider", fn () => Credential::whereNull('store_id')
            ->where('feature', 'social')
            ->where('provider', $provider)
            ->first());
    }

    /** Memoise including "no row" (the common case), which `??=` would re-query. */
    private function memo(string $key, \Closure $load): ?Credential
    {
        if (! array_key_exists($key, $this->rowMemo)) {
            $this->rowMemo[$key] = $load();
        }

        return $this->rowMemo[$key];
    }
}
