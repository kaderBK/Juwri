<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Services\WhatsApp;

use Juwri\Core\Models\Credential;
use Juwri\Modules\Messaging\Services\WhatsApp\Drivers\JuwriHostedProvider;
use Juwri\Modules\Messaging\Services\WhatsApp\Drivers\LinkProvider;

/**
 * Resolves a WhatsApp provider driver for a restaurant, applying BYOK-vs-platform
 * secrets from the Credential vault (feature='whatsapp'). Provider plugins
 * register their drivers via register(); the 'link' driver is a built-in
 * guaranteed fallback. See rev/whatsapp/IMPLEMENTATION-SPEC.md §2.3.
 */
class WhatsAppProviderFactory
{
    /** @var array<string,class-string> provider key => driver class */
    protected static array $registry = [];

    /** Register a driver (called from plugin service providers on boot). */
    public static function register(string $key, string $class): void
    {
        static::$registry[$key] = $class;
    }

    /** @return string[] registered provider keys (including built-in 'link'). */
    public static function available(): array
    {
        return array_values(array_unique(array_merge(['link'], array_keys(static::$registry))));
    }

    /** Reset the registry (test helper). */
    public static function flush(): void
    {
        static::$registry = [];
    }

    /**
     * Build the active provider for a restaurant. Falls back to the built-in
     * link driver when the chosen provider is unknown/unconfigured.
     */
    public function make(?int $storeId): WhatsAppProviderInterface
    {
        $key = $this->activeProviderKey($storeId);
        $provider = $this->makeByKey($key, $storeId);

        if ($provider && $provider->isConfigured()) {
            return $provider;
        }

        return $this->makeByKey('link', $storeId) ?? new LinkProvider('link');
    }

    /** Build a specific provider by key, or null if not registered. */
    public function makeByKey(string $key, ?int $storeId): ?WhatsAppProviderInterface
    {
        if ($key === 'link') {
            return new LinkProvider('link');
        }
        if ($key === 'juwri_hosted') {
            // self-hosted on juwri's hosted messaging: no key here, the sending store rides with every call
            return (new JuwriHostedProvider('juwri_hosted'))->forStore($storeId);
        }

        $class = static::$registry[$key] ?? null;
        if (! $class) {
            return null;
        }

        $resolved = $this->resolveSecrets($key, $storeId);

        return new $class($key, $resolved['secrets'], $resolved['byok']);
    }

    /** True when the restaurant is using its own key (BYOK) for this provider. */
    public function isByok(string $key, ?int $storeId): bool
    {
        return $this->resolveSecrets($key, $storeId)['byok'];
    }

    /** The provider key a restaurant has selected (or the platform default). */
    public function activeProviderKey(?int $storeId): string
    {
        // Platform default: admin override (settings) wins over config/env.
        $default = config('settings.whatsapp_default_provider') ?: config('whatsapp.default_provider', 'link');
        if (! $storeId) {
            return $default;
        }
        $chosen = store_options($storeId, 'whatsapp_provider');

        return $chosen ?: $default;
    }

    /**
     * @return array{secrets:array,byok:bool} restaurant BYOK secrets when present,
     *                                        else platform secrets from config('settings.whatsapp_<key>_*').
     */
    protected function resolveSecrets(string $key, ?int $storeId): array
    {
        // 1. Restaurant BYOK row wins.
        if ($storeId) {
            $cred = Credential::where('store_id', $storeId)
                ->where('feature', 'whatsapp')
                ->where('provider', $key)
                ->where('active', true)
                ->first();
            if ($cred && ! empty($cred->secrets)) {
                return ['secrets' => (array) $cred->secrets, 'byok' => true];
            }
        }

        // 2. Platform default from the Credential vault (store_id null).
        $platform = Credential::whereNull('store_id')
            ->where('feature', 'whatsapp')
            ->where('provider', $key)
            ->where('active', true)
            ->first();
        if ($platform && ! empty($platform->secrets)) {
            return ['secrets' => (array) $platform->secrets, 'byok' => false];
        }

        // 3. Platform settings (Option table) fallback: whatsapp_<key>_<field>.
        $prefix = 'whatsapp_'.$key.'_';
        $secrets = [];
        foreach ((array) config('settings') as $name => $value) {
            if (str_starts_with((string) $name, $prefix)) {
                $secrets[substr($name, strlen($prefix))] = $value;
            }
        }

        return ['secrets' => $secrets, 'byok' => false];
    }
}
