<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ai\Services;

use Illuminate\Support\Facades\Log;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\SsrfGuard;
use Juwri\Modules\Ai\Contracts\AiProviderChain;
use Juwri\Modules\Ai\Services\Drivers\AnthropicProvider;
use Juwri\Modules\Ai\Services\Drivers\JuwriHostedProvider;
use Juwri\Modules\Ai\Services\Drivers\OpenAiCompatibleProvider;

/**
 * Builds provider instances from config, applying per-restaurant BYOK overrides
 * from the Credential vault (feature = 'ai').
 *
 * BYOK secrets shape (either form is accepted):
 *   flat:  {"provider":"openai","api_key":"sk-...","model":"gpt-4o"}
 *   map:   {"openai":{"api_key":"sk-..."},"mimo":{"api_key":"...","model":"..."}}
 */
class AiProviderFactory implements AiProviderChain
{
    /**
     * Build a single provider by key, or null if it isn't defined.
     */
    public function make(string $key, ?int $storeId = null): ?AiProviderInterface
    {
        $config = config("ai.providers.$key");
        if (! $config) {
            return null;
        }

        $config = $this->applyByok($key, $config, $storeId);

        return match ($config['driver'] ?? 'openai') {
            'anthropic' => new AnthropicProvider($key, $config),
            'juwri_hosted' => new JuwriHostedProvider($key, $config, $storeId),
            default => new OpenAiCompatibleProvider($key, $config),
        };
    }

    /**
     * The provider keys that make up the default->fallback chain, in order and
     * de-duplicated. Shared by chain() and describeChain() so both walk the
     * same resolution order.
     *
     * @return string[]
     */
    public function chainOrder(bool $needsVision = false): array
    {
        $order = array_merge(
            [config('ai.default')],
            (array) config('ai.fallback', [])
        );

        if ($needsVision) {
            // Prefer vision-capable providers first, preserving order.
            $order = array_merge((array) config('ai.vision', []), $order);
        }

        $seen = [];
        $keys = [];
        foreach ($order as $key) {
            if (! $key || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $keys[] = $key;
        }

        // HPMS E14(a): drop providers the platform admin has deactivated
        // (Credential feature=ai, store_id null, active=false) — the env/config
        // default chain used to ignore the admin toggle entirely.
        $inactive = self::adminInactiveKeys();
        if ($inactive !== []) {
            $keys = array_values(array_filter($keys, fn ($k) => ! in_array($k, $inactive, true)));
        }

        return $keys;
    }

    /**
     * Platform provider keys the admin has switched OFF. Empty on any read error
     * (fail-open: an inaccessible toggle table must not disable AI platform-wide).
     *
     * @return array<int,string>
     */
    public static function adminInactiveKeys(): array
    {
        try {
            // A provider is admin-DEACTIVATED (dropped from the chain) only when its
            // inactive platform Credential carries NO usable key. An inactive row that
            // still holds an api_key is merely a disabled DB override — the provider
            // stays in the chain via its env/config key (see
            // AiPlatformCredentialTest::test_inactive_platform_credential_falls_back_to_env).
            return Credential::whereNull('store_id')
                ->where('feature', 'ai')
                ->where('active', false)
                ->get()
                ->filter(function ($c) {
                    $secrets = (array) $c->secrets;

                    return empty($secrets['api_key']);
                })
                ->pluck('provider')->map(fn ($p) => (string) $p)->values()->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * HPMS E14(a): provider keys the owner may pick for BYOK — the curated set
     * minus the ones the admin deactivated.
     *
     * @return array<int,string>
     */
    public static function activeProviderKeys(): array
    {
        $all = array_keys((array) config('ai.providers', []));
        $inactive = self::adminInactiveKeys();

        return array_values(array_filter($all, fn ($k) => ! in_array($k, $inactive, true)));
    }

    /**
     * Ordered list of configured providers for the default->fallback chain.
     * Only providers that actually have a key are returned.
     *
     * @return AiProviderInterface[]
     */
    public function chain(?int $storeId = null, bool $needsVision = false): array
    {
        $chain = [];
        foreach ($this->chainOrder($needsVision) as $key) {
            $provider = $this->make($key, $storeId);
            if (! $provider || ! $provider->isConfigured()) {
                continue;
            }
            if ($needsVision && ! $provider->supportsVision()) {
                continue;
            }
            if ($this->trainingBlocked($key, $storeId)) {
                continue;
            }
            $chain[] = $provider;
        }

        return $chain;
    }

    /**
     * Ordered image-generation chain (config('ai.image')): configured providers
     * that actually support /images/generations, BYOK overrides applied.
     * FEATURES-5 Track B4.
     *
     * @return AiProviderInterface[]
     */
    public function imageChain(?int $storeId = null): array
    {
        $order = array_values(array_filter((array) config('ai.image', [])));
        $seen = [];
        $chain = [];
        foreach ($order as $key) {
            if (! $key || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $provider = $this->make($key, $storeId);
            if ($provider && $provider->isConfigured() && $provider->supportsImageGeneration()
                && ! $this->trainingBlocked($key, $storeId)) {
                $chain[] = $provider;
            }
        }

        return $chain;
    }

    /**
     * Ordered speech-to-text chain (config('ai.transcribe')): configured
     * providers that actually support /audio/transcriptions, BYOK overrides
     * applied. ZERO-TOUCH Track B.
     *
     * @return AiProviderInterface[]
     */
    public function transcribeChain(?int $storeId = null): array
    {
        $order = array_values(array_filter((array) config('ai.transcribe', [])));
        $seen = [];
        $chain = [];
        foreach ($order as $key) {
            if (! $key || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $provider = $this->make($key, $storeId);
            if ($provider && $provider->isConfigured() && $provider->supportsTranscription()
                && ! $this->trainingBlocked($key, $storeId)) {
                $chain[] = $provider;
            }
        }

        return $chain;
    }

    /**
     * Describe the resolved transcription chain WITHOUT network calls — for
     * the owner/admin "which provider handles voice" read-only state.
     * Mirrors transcribeChain() exactly (order + capability + key filters).
     *
     * @return array<int,array{provider:string,model:?string,key_source:string}>
     */
    public function describeTranscribeChain(?int $storeId = null): array
    {
        $order = array_values(array_filter((array) config('ai.transcribe', [])));
        $seen = [];
        $out = [];
        foreach ($order as $key) {
            if (! $key || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $config = config("ai.providers.$key");
            if (! $config) {
                continue;
            }

            $source = 'platform';
            $config = $this->applyByok($key, $config, $storeId, $source);

            if (empty($config['api_key']) || empty($config['supports_transcription'])) {
                continue;
            }
            if (self::platformKeyBlocked($config, $source)) {
                continue;
            }

            $out[] = [
                'provider' => $key,
                'model' => $config['transcription_model'] ?? null,
                'key_source' => $source,
            ];
        }

        return $out;
    }

    /**
     * Describe the resolved default->fallback chain WITHOUT building providers
     * or making network calls. Mirrors chain() exactly (same order, same
     * "only providers that have a usable key" filter), but returns a plain
     * ordered array describing each active provider:
     *
     *   [
     *     ['provider' => 'mimo', 'model' => 'mimo-v2.5-pro', 'key_source' => 'byok'],
     *     ['provider' => 'openai', 'model' => 'gpt-4o', 'key_source' => 'platform'],
     *     ...
     *   ]
     *
     * key_source is one of:
     *   'platform' — the env/config platform key is used,
     *   'byok'     — the merchant's own Credential(feature='ai') key,
     *   'reseller' — the owning reseller's Credential(feature='reseller_ai') key.
     *
     * @return array<int,array{provider:string,model:?string,key_source:string}>
     */
    public function describeChain(?int $storeId = null, bool $needsVision = false): array
    {
        $out = [];
        foreach ($this->chainOrder($needsVision) as $key) {
            $config = config("ai.providers.$key");
            if (! $config) {
                continue;
            }

            $source = 'platform';
            $config = $this->applyByok($key, $config, $storeId, $source);

            // Mirror chain()/isConfigured(): skip providers with no usable key.
            if (empty($config['api_key'])) {
                continue;
            }
            if ($needsVision && ! ($config['supports_vision'] ?? false)) {
                continue;
            }
            if (self::platformKeyBlocked($config, $source)) {
                continue;
            }

            $out[] = [
                'provider' => $key,
                'model' => $config['model'] ?? null,
                'key_source' => $source,
            ];
        }

        return $out;
    }

    /**
     * P1-23 (Terms 5.3, DPA 11.2): with ai.require_no_training on, the PLATFORM key of a provider whose terms are not
     * known to exclude training (config ai.providers.<key>.no_training false) is never used — the provider is left out
     * of every chain. A store's or reseller's own key (BYOK) is their own contract with the provider: not filtered.
     */
    private function trainingBlocked(string $key, ?int $storeId): bool
    {
        if (! config('ai.require_no_training')) {
            return false; // checked first: no extra credential lookups when the switch is off
        }
        $config = config("ai.providers.$key");
        if (! is_array($config)) {
            return true;
        }
        $source = 'platform';
        $config = $this->applyByok($key, $config, $storeId, $source);

        return self::platformKeyBlocked($config, $source);
    }

    /** @param  array<string, mixed>  $config  a provider config after applyByok() */
    public static function platformKeyBlocked(array $config, ?string $source): bool
    {
        return (bool) config('ai.require_no_training')
            && $source === 'platform'
            && ! (bool) ($config['no_training'] ?? false);
    }

    /**
     * @param  string  $source  Out-param set to 'platform'|'byok'|'reseller' to
     *                          report which key ultimately overrode this config.
     */
    protected function applyByok(string $key, array $config, ?int $storeId, ?string &$source = null): array
    {
        $source = 'platform';

        $cred = null;
        $matched = 'byok';

        if ($storeId) {
            // The Integrations vault stores one Credential row per AI provider
            // (feature='ai', provider='mimo'|'kimi'|'openai'|...), secrets =
            // {api_key, model?, base_url?}. Override this provider's config from
            // its own row.
            $cred = Credential::where('store_id', $storeId)
                ->where('feature', 'ai')
                ->where('provider', $key)
                ->where('active', true)
                ->first();

            // Reseller v2 P5 (5.6): merchant BYOK → RESELLER BYOK → platform. If
            // the merchant has no key of its own, fall back to the owning
            // reseller's reseller_ai vault row (the reseller pays their provider
            // directly).
            if (! $cred || ! is_array($cred->secrets)) {
                $resellerId = $this->resellerIdForRestaurant($storeId);
                if ($resellerId) {
                    $matched = 'reseller';
                    $cred = Credential::where('reseller_id', $resellerId)
                        ->whereNull('store_id')
                        ->where('feature', 'reseller_ai')
                        ->where('provider', $key)
                        ->where('active', true)
                        ->first();
                }
            }
        }

        // features-4 A5: platform-level Credential (store_id NULL,
        // feature='ai') set by superadmin. It slots BELOW merchant/reseller BYOK
        // but ABOVE the env/config default — a DB-set platform key overrides env
        // so ops can rotate keys without a redeploy. It is still a PLATFORM key
        // (source stays 'platform'). Consulted only when no merchant/reseller row
        // supplied a credential, and also when there is no restaurant at all
        // (e.g. superadmin describeChain preview).
        if (! $cred || ! is_array($cred->secrets)) {
            $matched = 'platform';
            $cred = Credential::whereNull('store_id')
                ->where('feature', 'ai')
                ->where('provider', $key)
                ->where('active', true)
                ->first();
        }

        if (! $cred || ! is_array($cred->secrets)) {
            return $config;
        }

        $override = array_filter([
            'api_key' => $cred->secrets['api_key'] ?? null,
            'model' => $cred->secrets['model'] ?? null,
            'base_url' => $cred->secrets['base_url'] ?? null,
            // ZERO-TOUCH Track B: per-key STT model override (admin/BYOK).
            'transcription_model' => $cred->secrets['transcription_model'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        // P6-68b: a vault base_url (merchant BYOK, reseller or platform-admin row) is where the server POSTs the
        // prompt + key, so it must be a public https host — never loopback/private/cloud-metadata. Only the
        // platform-admin row may point at a private host (self-hosted LLM on the LAN), and only when the operator
        // sets AI_ALLOW_PRIVATE_BASE_URL=true. A refused URL is dropped (the config default is used instead).
        if (isset($override['base_url']) && ! self::baseUrlAllowed((string) $override['base_url'], $matched === 'platform')) {
            Log::warning('[ai] refused a non-public provider base_url; using the default', ['provider' => $key, 'source' => $matched]);
            unset($override['base_url']);
        }

        // Report the key's origin only when this credential actually supplied a
        // key; a row that overrides only the model/base_url leaves the platform
        // key (and therefore the 'platform' source) in place.
        if (! empty($override['api_key'])) {
            $source = $matched;
        }

        return array_merge($config, $override);
    }

    /** P6-68b: may the server send provider calls to this vault-supplied base URL? */
    public static function baseUrlAllowed(string $url, bool $platformRow): bool
    {
        if ($platformRow && (bool) config('ai.allow_private_base_url', false)) {
            return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
        }

        return SsrfGuard::isSafePublicUrl($url);
    }

    /**
     * Credit packs: whose key provider $key runs on for this restaurant — 'byok' (the store's own), 'reseller' (its
     * reseller's) or 'platform' (the platform pays the provider). Same resolution as make().
     */
    public function keySource(string $key, ?int $storeId): string
    {
        $config = config("ai.providers.$key");
        if (! is_array($config)) {
            return 'platform';
        }
        $source = 'platform';
        $this->applyByok($key, $config, $storeId, $source);

        return $source;
    }

    /**
     * Whether this restaurant (or its owning reseller) supplies its own AI key.
     * BYOK calls burn the owner's wallet, not ours — the AbuseGuard spend caps
     * skip them (they are still metered).
     */
    public function hasByok(?int $storeId): bool
    {
        if (! $storeId) {
            return false;
        }

        try {
            $merchant = Credential::where('store_id', $storeId)
                ->where('feature', 'ai')
                ->where('active', true)
                ->whereNotNull('secrets')
                ->exists();
            if ($merchant) {
                return true;
            }

            $resellerId = $this->resellerIdForRestaurant($storeId);
            if ($resellerId) {
                return Credential::where('reseller_id', $resellerId)
                    ->whereNull('store_id')
                    ->where('feature', 'reseller_ai')
                    ->where('active', true)
                    ->whereNotNull('secrets')
                    ->exists();
            }
        } catch (\Throwable $e) {
            return false;
        }

        return false;
    }

    /**
     * Curated model list for a provider's BYOK dropdown (config('ai.providers.
     * <key>.allowed_models')). Empty array = no suggestions (free-text only).
     * The picker is a suggestion source, NOT a whitelist — owners may still type
     * any model the provider accepts (see setByokModel free-text fallback).
     *
     * @return string[]
     */
    public function allowedModels(string $provider): array
    {
        $models = config("ai.providers.$provider.allowed_models", []);

        return array_values(array_filter(array_map(
            fn ($m) => is_string($m) ? trim($m) : '',
            (array) $models
        )));
    }

    /**
     * The full BYOK model-picker source for every configured provider, keyed by
     * provider. Each entry carries the platform default model and the curated
     * dropdown list, for rendering a per-provider picker with a free-text box.
     *
     * @return array<string,array{default:?string,allowed_models:string[]}>
     */
    public function modelPickerOptions(): array
    {
        $out = [];
        foreach ((array) config('ai.providers', []) as $key => $cfg) {
            $out[$key] = [
                'default' => $cfg['model'] ?? null,
                'allowed_models' => $this->allowedModels($key),
            ];
        }

        return $out;
    }

    /**
     * Read the merchant's currently-saved BYOK model override for a provider,
     * or null when none is set. Does NOT fall back to the reseller/platform
     * model — this is the owner's own explicit choice for the settings form.
     */
    public function getByokModel(int $storeId, string $provider): ?string
    {
        $cred = Credential::where('store_id', $storeId)
            ->where('feature', 'ai')
            ->where('provider', $provider)
            ->first();

        if (! $cred || ! is_array($cred->secrets)) {
            return null;
        }

        $model = $cred->secrets['model'] ?? null;

        return (is_string($model) && $model !== '') ? $model : null;
    }

    /**
     * Persist the merchant's BYOK model override for a provider. Free-text
     * fallback: any non-empty string is accepted (the curated allowed_models
     * list is a UI suggestion source only, not an enforced whitelist). Passing
     * null/empty clears the override so the platform/config default resumes.
     *
     * Upserts the Credential(feature='ai', provider=...) row's secrets.model
     * without disturbing an existing api_key/base_url. Returns the saved row.
     */
    public function setByokModel(int $storeId, string $provider, ?string $model): Credential
    {
        $model = is_string($model) ? trim($model) : '';

        $cred = Credential::firstOrNew([
            'store_id' => $storeId,
            'feature' => 'ai',
            'provider' => $provider,
        ]);

        $secrets = is_array($cred->secrets) ? $cred->secrets : [];

        if ($model === '') {
            unset($secrets['model']);
        } else {
            $secrets['model'] = $model;
        }

        $cred->secrets = $secrets;
        // Preserve an already-active row; default a brand-new row to active so
        // the override takes effect immediately.
        if (! $cred->exists) {
            $cred->active = $cred->active ?? true;
        }
        $cred->save();

        return $cred;
    }

    /** Resolve the reseller that owns a restaurant (via its owner user), or null. */
    public function resellerIdForRestaurant(int $storeId): ?int
    {
        try {
            $store = Store::find($storeId);
            $owner = $store ? User::find($store->user_id) : null;

            return $owner?->reseller_id ? (int) $owner->reseller_id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
