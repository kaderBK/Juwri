<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services;

use Illuminate\Support\Facades\Schema;
use Juwri\Core\Http\Hosts\HostMap;
use Juwri\Core\Models\Store;
use Juwri\Modules\Storefront\Models\StoreDomain;

/**
 * P1-20 (J28): THE store address resolver — inbound (host → store) and outbound (store → primary URL).
 *
 * Address forms of a store, in resolution order:
 *  1. a verified custom domain (`menu.example.com`; `www.` of a verified apex resolves to it as an alias);
 *  2. a verified platform subdomain row (`{label}.<storefront host>`);
 *  3. the implicit path form `<storefront base>/{slug}`.
 * A module may resolve further store hosts through {@see HostMap::addStoreHostResolver()} (consulted only when no
 * verified row matches; the row it names must answer on the same host).
 * The primary address is the store's verified `is_primary` row, else the path form. Unverified rows never
 * resolve and are never primary (domain takeover guard).
 *
 * Request-scoped singleton (memoised lookups); bound in the Storefront provider.
 */
final class StoreAddressResolver
{
    /** @var array<string, StoreDomain|false> */
    private array $byHost = [];

    /** @var array<int, StoreDomain|false> */
    private array $primary = [];

    private ?bool $tableExists = null;

    /**
     * Verified store_domains row answering on $host (custom domain, its www alias, or platform subdomain).
     */
    public function domainForHost(string $host): ?StoreDomain
    {
        $host = StoreDomain::normalizeHost($host);
        if ($host === '' || ! $this->ready()) {
            return null;
        }
        if (! array_key_exists($host, $this->byHost)) {
            $row = $this->verifiedRow($host);
            if (! $row && str_starts_with($host, 'www.')) {
                $apex = $this->verifiedRow(substr($host, 4));
                $row = ($apex && $apex->type === StoreDomain::TYPE_CUSTOM) ? $apex : null;
            }
            if (! $row && ($extra = HostMap::resolveExtraStoreHost($host)) !== null) {
                // A module-registered resolver (an imported platform subdomain of a migrated install): the row must
                // still answer on exactly this host, so a resolver can never point a host at another store's row.
                $row = StoreDomain::query()->withoutGlobalScopes()->whereKey($extra)->where('host', $host)->first();
            }
            $this->byHost[$host] = $row ?: false;
        }

        return $this->byHost[$host] ?: null;
    }

    public function storeForHost(string $host): ?Store
    {
        $row = $this->domainForHost($host);

        return $row ? Store::find($row->store_id) : null;
    }

    /** The verified primary row of a store, or null when the primary address is the path form. */
    public function primaryDomain(int $storeId): ?StoreDomain
    {
        if (! $this->ready()) {
            return null;
        }
        if (! array_key_exists($storeId, $this->primary)) {
            $this->primary[$storeId] = StoreDomain::query()->withoutGlobalScopes()
                ->where('store_id', $storeId)->where('is_primary', true)->whereNotNull('verified_at')
                ->orderBy('id')->first() ?: false;
        }

        return $this->primary[$storeId] ?: null;
    }

    /** P6-306: load the primary rows of many stores in ONE query, so a list of stores does not query per store. */
    /** @param  array<int, int|string>  $storeIds */
    public function primeBatch(array $storeIds): void
    {
        $ids = array_values(array_filter(array_map('intval', $storeIds), fn ($id) => $id > 0 && ! array_key_exists($id, $this->primary)));
        if ($ids === [] || ! $this->ready()) {
            return;
        }
        $rows = StoreDomain::query()->withoutGlobalScopes()
            ->whereIn('store_id', $ids)->where('is_primary', true)->whereNotNull('verified_at')
            ->orderBy('id')->get()->unique('store_id')->keyBy('store_id');
        foreach ($ids as $id) {
            $this->primary[$id] = $rows->get($id) ?: false;
        }
    }

    /** Primary host of a store, or null for the path form. */
    public function primaryHost(int $storeId): ?string
    {
        return $this->primaryDomain($storeId)?->host;
    }

    /**
     * Absolute base URL of a store's primary address, without a trailing slash:
     * `https://menu.example.com`, `https://{label}.juwri.store`, or `<storefront base>/{slug}`.
     */
    public function baseUrl(Store|int|string $store): string
    {
        [$id, $slug] = $this->idAndSlug($store);
        $row = $id ? $this->primaryDomain($id) : null;
        if ($row) {
            // Custom domains are always served over TLS (Caddy on-demand / Cloudflare for SaaS);
            // platform subdomains follow the storefront base URL's scheme (http on local *.test).
            $scheme = $row->type === StoreDomain::TYPE_CUSTOM ? 'https' : HostMap::storefrontScheme();

            return $scheme.'://'.$row->host;
        }

        return HostMap::storefrontUrl().'/'.rawurlencode((string) $slug);
    }

    /**
     * Absolute URL of $path inside a store's primary address. $path is store-relative ('', 'bio',
     * 'reservation/deposit/abc?x=1'); it can never change the host.
     */
    public function url(Store|int|string $store, string $path = ''): string
    {
        $base = $this->baseUrl($store);
        $path = self::safePath($path);

        return $path === '' ? $base : $base.'/'.$path;
    }

    /**
     * The same storefront page at the store's primary address: $url may be any address form of the store
     * (path form on a platform host, host form on a store host, with or without a locale prefix). The
     * locale prefix and query are kept; the host always comes from the primary address.
     */
    public function rebase(Store|int|string $store, string $url): string
    {
        [$id, $slug] = $this->idAndSlug($store);
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $query = (string) (parse_url($url, PHP_URL_QUERY) ?? '');
        ['locale' => $locale, 'rest' => $rest] = StorePath::rest($path, (string) $slug);

        return $this->localized($id, (string) $slug, $locale, $rest).($query !== '' ? '?'.$query : '');
    }

    /** Primary-address URL of a store-relative $rest, with an optional locale prefix. */
    public function localized(?int $storeId, string $slug, ?string $locale, string $rest): string
    {
        $rest = self::safePath($rest);
        $row = $storeId ? $this->primaryDomain($storeId) : null;
        if ($row) {
            $scheme = $row->type === StoreDomain::TYPE_CUSTOM ? 'https' : HostMap::storefrontScheme();
            $base = $scheme.'://'.$row->host;
            $tail = implode('/', array_filter([$locale, $rest], fn ($p) => $p !== null && $p !== ''));

            return $tail === '' ? $base : $base.'/'.$tail;
        }
        $tail = implode('/', array_filter([$locale, rawurlencode($slug), $rest], fn ($p) => $p !== null && $p !== ''));

        return HostMap::storefrontUrl().'/'.$tail;
    }

    /**
     * A store-relative path with any scheme/host/leading slashes removed, so it can only ever be appended
     * to a trusted base (open-redirect guard for the primary 301s).
     */
    public static function safePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $path)) {
            $path = (string) preg_replace('#^[a-z][a-z0-9+.-]*:/*[^/]*#i', '', $path);
        }

        return ltrim($path, '/');
    }

    public function flush(): void
    {
        $this->byHost = [];
        $this->primary = [];
    }

    /** @return array{0: int|null, 1: string|null} */
    private function idAndSlug(Store|int|string $store): array
    {
        if ($store instanceof Store) {
            return [(int) $store->id, (string) $store->slug];
        }
        if (is_int($store) || ctype_digit((string) $store)) {
            $slug = Store::query()->whereKey((int) $store)->value('slug');
            if ($slug !== null) {
                return [(int) $store, (string) $slug];
            }
        }
        $id = Store::query()->where('slug', (string) $store)->value('id');

        return [$id ? (int) $id : null, (string) $store];
    }

    private function verifiedRow(string $host): ?StoreDomain
    {
        return StoreDomain::query()->withoutGlobalScopes()->whereNotNull('verified_at')->where('host', $host)->first();
    }

    private function ready(): bool
    {
        if ($this->tableExists === null) {
            try {
                $this->tableExists = Schema::hasTable('store_domains');
            } catch (\Throwable) {
                $this->tableExists = false;
            }
        }

        return $this->tableExists;
    }
}
