<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustHosts as Middleware;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Contracts\Tenancy\ResellerContext;
use Juwri\Core\Http\Hosts\HostMap;
use Juwri\Modules\Storefront\Models\StoreDomain;

class TrustHosts extends Middleware
{
    /**
     * Get the host patterns that should be trusted.
     *
     * The platform host (and its subdomains), every verified custom storefront
     * domain, and every active reseller domain are trusted; any other Host
     * header is rejected, closing the reset/verification-link poisoning vector
     * (S3). The custom-domain union MUST stay comprehensive or legitimate
     * white-label storefronts would 400 before tenant resolution runs.
     *
     * @return array<int, string|null>
     */
    public function hosts(): array
    {
        $hosts = [
            $this->allSubdomainsOfApplicationUrl(),
        ];

        // P1-20 split mode: the admin, marketing and storefront hosts (+ platform subdomains of the
        // storefront host, which only serve a store once a verified store_domains row exists).
        if (HostMap::split()) {
            foreach ([HostMap::adminHost(), HostMap::marketingHost(), 'www.'.HostMap::marketingHost()] as $platform) {
                $hosts[] = '^'.preg_quote($platform).'$';
            }
            if (($storefront = HostMap::storefrontHost()) !== '') {
                $hosts[] = '^(.+\\.)?'.preg_quote($storefront).'$';
            }
        }

        // Module-registered extra storefront hosts (a migrated install keeps the old platform host): the host, its
        // www. twin and ONE label below it; which subdomain serves which store is still decided by the store
        // resolver (unknown = 404). Deeper names are not trusted.
        array_push($hosts, ...HostMap::storefrontAliasPatterns());

        foreach ($this->verifiedCustomDomains() as $domain) {
            // Symfony treats each pattern as a regex; anchor an exact match.
            $hosts[] = '^'.preg_quote($domain).'$';
        }

        return $hosts;
    }

    /**
     * Verified custom storefront domains, lower-cased and de-duped.
     *
     * Cached briefly so this global-stack lookup does not hit the DB on every
     * request, and guarded so install/migrate (no table yet) cannot fatal.
     *
     * @return array<int, string>
     */
    protected function verifiedCustomDomains(): array
    {
        try {
            return Cache::remember('trusted_custom_domains', 60, function () {
                $restaurantDomains = Schema::hasTable('store_domains')
                    ? StoreDomain::query()->withoutGlobalScopes()
                        ->where('type', StoreDomain::TYPE_CUSTOM)
                        ->whereNotNull('verified_at')
                        ->pluck('host')
                        ->all()
                    : [];

                $resellerDomains = Schema::hasTable('resellers')
                    ? app(ResellerContext::class)->activeCustomDomains()
                    : [];

                return collect([...$restaurantDomains, ...$resellerDomains])
                    ->filter()
                    ->map(fn ($domain) => strtolower(trim((string) $domain)))
                    ->filter()
                    // www is the customary alias of a registered domain and the
                    // cert/DNS instructions cover it — trust both forms or every
                    // https://www.{custom-domain} request 400s on this global
                    // stack before ResolveCustomDomain ever runs (2026-09-01).
                    ->flatMap(fn ($domain) => [$domain, 'www.'.$domain])
                    ->unique()
                    ->values()
                    ->all();
            });
        } catch (\Throwable $e) {
            return [];
        }
    }
}
