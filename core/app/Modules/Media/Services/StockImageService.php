<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Media\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Models\Credential;
use Juwri\Core\Services\SsrfGuard;
use Juwri\Modules\Media\Contracts\StockImages;

/**
 * Unified search facade over external image providers:
 *   - pexels      (feature=stock,        provider=pexels)      — licensed, safe
 *   - unsplash    (feature=stock,        provider=unsplash)    — licensed, safe
 *   - google_cse  (feature=media_search, provider=google_cse) — arbitrary web
 *                 images, UNLICENSED → BYOK-only, no platform fallback, rights
 *                 warning shown in the UI before copy.
 *
 * Every driver returns a normalised result shape:
 *   ['id','thumb','full','author','author_url','source','source_url','download']
 *
 * Keys resolve through the Credential vault: owner BYOK override → platform
 * default (except google_cse which is BYOK-only). Live keys are DEFERRED — the
 * service is fully exercised in tests via Http::fake().
 */
class StockImageService implements StockImages
{
    /** Providers that carry a licence and may use a platform-default key. */
    public const SAFE_PROVIDERS = ['pexels', 'unsplash'];

    // UNSAFE_PROVIDERS (unlicensed web images, BYOK-only) is declared on the StockImages contract (P1-08).

    public function isSupported(string $provider): bool
    {
        return in_array($provider, array_merge(self::SAFE_PROVIDERS, self::UNSAFE_PROVIDERS), true);
    }

    /** Whether the given provider is configured (has usable creds) for this tenant. */
    public function isConfigured(string $provider, ?int $storeId = null): bool
    {
        return $this->creds($provider, $storeId) !== null;
    }

    /** List provider keys that are configured and usable for this tenant. */
    public function availableProviders(?int $storeId = null): array
    {
        $out = [];
        foreach (array_merge(self::SAFE_PROVIDERS, self::UNSAFE_PROVIDERS) as $p) {
            if ($this->isConfigured($p, $storeId)) {
                $out[] = $p;
            }
        }

        return $out;
    }

    /**
     * Search a provider. Returns ['results'=>[...normalised], 'error'=>?string].
     *
     * @return array{results:array<int,array>,error:?string}
     */
    public function search(string $provider, string $query, int $page = 1, ?int $storeId = null): array
    {
        $query = trim($query);
        if (! $this->isSupported($provider)) {
            return ['results' => [], 'error' => ___('Unknown image provider.')];
        }
        if ($query === '') {
            return ['results' => [], 'error' => null];
        }

        $creds = $this->creds($provider, $storeId);
        if ($creds === null) {
            return ['results' => [], 'error' => ___('This image provider is not configured. Add an API key in settings.')];
        }

        try {
            return match ($provider) {
                'pexels' => ['results' => $this->searchPexels($creds, $query, $page), 'error' => null],
                'unsplash' => ['results' => $this->searchUnsplash($creds, $query, $page), 'error' => null],
                'google_cse' => ['results' => $this->searchGoogle($creds, $query, $page), 'error' => null],
                default => ['results' => [], 'error' => ___('Unknown image provider.')],
            };
        } catch (\Throwable $e) {
            Log::warning("[StockImage] {$provider} search failed: ".$e->getMessage());

            return ['results' => [], 'error' => ___('Image search failed. Check the API key or try again.')];
        }
    }

    /**
     * Unsplash API terms REQUIRE triggering the download endpoint whenever an
     * image is actually used. No-op for other providers. Failures are swallowed.
     */
    public function triggerDownload(string $provider, ?string $downloadLocation, ?int $storeId = null): void
    {
        if ($provider !== 'unsplash' || empty($downloadLocation)) {
            return;
        }
        $creds = $this->creds('unsplash', $storeId);
        if ($creds === null || empty($creds['access_key'])) {
            return;
        }
        // SSRF: $downloadLocation is caller-supplied — guard it (and every redirect hop)
        // before fetching. It also carries the tenant's Unsplash key in an Authorization
        // header, so an internal/attacker host must never receive this request.
        if (! SsrfGuard::isSafePublicUrl($downloadLocation)) {
            return;
        }
        try {
            Http::timeout(10)
                ->withHeaders(['Authorization' => 'Client-ID '.$creds['access_key']])
                ->withOptions(SsrfGuard::pinnedOptions($downloadLocation) ?? throw new \RuntimeException('SSRF: URL is not a safe public https endpoint'))
                ->get($downloadLocation);
        } catch (\Throwable $e) {
            Log::info('[StockImage] unsplash download-trigger failed: '.$e->getMessage());
        }
    }

    // ---------------------------------------------------------------------
    // Credential resolution
    // ---------------------------------------------------------------------

    /**
     * Resolve provider credentials. Safe providers use the standard vault
     * (BYOK override → platform default). google_cse is BYOK-only: it never
     * falls back to a platform key (unlicensed results must be owner-opt-in).
     */
    protected function creds(string $provider, ?int $storeId): ?array
    {
        if ($provider === 'google_cse') {
            if (! $storeId) {
                return null;
            }
            $row = Credential::where('store_id', $storeId)
                ->where('feature', 'media_search')
                ->where('provider', 'google_cse')
                ->where('active', true)
                ->first();
            $secrets = $row?->secrets;

            return (is_array($secrets) && ! empty($secrets['api_key']) && ! empty($secrets['cx'])) ? $secrets : null;
        }

        // Pexels / Unsplash — standard BYOK→platform resolution, but scoped to
        // the specific provider row (Credential::for resolves a whole feature).
        $secrets = $this->vaultSecrets('stock', $provider, $storeId);
        if ($secrets === null) {
            return null;
        }
        $keyField = $provider === 'unsplash' ? 'access_key' : 'api_key';

        return ! empty($secrets[$keyField]) ? $secrets : null;
    }

    /** BYOK (restaurant) → platform default, matching on feature+provider. */
    protected function vaultSecrets(string $feature, string $provider, ?int $storeId): ?array
    {
        if ($storeId) {
            $row = Credential::where('store_id', $storeId)
                ->where('feature', $feature)
                ->where('provider', $provider)
                ->where('active', true)
                ->first();
            if ($row && is_array($row->secrets)) {
                return $row->secrets;
            }
        }
        $row = Credential::whereNull('store_id')
            ->where('feature', $feature)
            ->where('provider', $provider)
            ->where('active', true)
            ->first();

        return ($row && is_array($row->secrets)) ? $row->secrets : null;
    }

    // ---------------------------------------------------------------------
    // Drivers
    // ---------------------------------------------------------------------

    protected function searchPexels(array $creds, string $query, int $page): array
    {
        $res = Http::timeout(20)
            ->withHeaders(['Authorization' => $creds['api_key']])
            ->get('https://api.pexels.com/v1/search', [
                'query' => $query,
                'page' => max(1, $page),
                'per_page' => 24,
            ]);
        if ($res->failed()) {
            throw new \RuntimeException('Pexels HTTP '.$res->status());
        }

        return collect($res->json('photos', []))->map(fn ($p) => [
            'id' => 'pexels-'.($p['id'] ?? ''),
            'thumb' => data_get($p, 'src.medium', ''),
            'full' => data_get($p, 'src.large2x', data_get($p, 'src.original', '')),
            'author' => $p['photographer'] ?? '',
            'author_url' => $p['photographer_url'] ?? '',
            'source' => 'pexels',
            'source_url' => $p['url'] ?? '',
            'download' => null,
        ])->filter(fn ($r) => $r['full'] !== '')->values()->all();
    }

    protected function searchUnsplash(array $creds, string $query, int $page): array
    {
        $res = Http::timeout(20)
            ->withHeaders(['Authorization' => 'Client-ID '.$creds['access_key']])
            ->get('https://api.unsplash.com/search/photos', [
                'query' => $query,
                'page' => max(1, $page),
                'per_page' => 24,
            ]);
        if ($res->failed()) {
            throw new \RuntimeException('Unsplash HTTP '.$res->status());
        }

        return collect($res->json('results', []))->map(fn ($p) => [
            'id' => 'unsplash-'.($p['id'] ?? ''),
            'thumb' => data_get($p, 'urls.small', ''),
            'full' => data_get($p, 'urls.regular', data_get($p, 'urls.full', '')),
            'author' => data_get($p, 'user.name', ''),
            'author_url' => data_get($p, 'user.links.html', ''),
            'source' => 'unsplash',
            'source_url' => data_get($p, 'links.html', ''),
            // Unsplash: this must be pinged on use (triggerDownload()).
            'download' => data_get($p, 'links.download_location', ''),
        ])->filter(fn ($r) => $r['full'] !== '')->values()->all();
    }

    protected function searchGoogle(array $creds, string $query, int $page): array
    {
        $start = (($page - 1) * 10) + 1; // CSE returns 10/page, 1-indexed
        $res = Http::timeout(20)->get('https://www.googleapis.com/customsearch/v1', [
            'key' => $creds['api_key'],
            'cx' => $creds['cx'],
            'q' => $query,
            'searchType' => 'image',
            'num' => 10,
            'start' => max(1, $start),
            'safe' => 'active',
        ]);
        if ($res->failed()) {
            throw new \RuntimeException('Google CSE HTTP '.$res->status());
        }

        return collect($res->json('items', []))->map(fn ($p) => [
            'id' => 'google-'.md5($p['link'] ?? ''),
            'thumb' => data_get($p, 'image.thumbnailLink', $p['link'] ?? ''),
            'full' => $p['link'] ?? '',
            'author' => data_get($p, 'displayLink', ''),
            'author_url' => data_get($p, 'image.contextLink', ''),
            'source' => 'google',
            'source_url' => data_get($p, 'image.contextLink', $p['link'] ?? ''),
            'download' => null,
        ])->filter(fn ($r) => $r['full'] !== '')->values()->all();
    }
}
