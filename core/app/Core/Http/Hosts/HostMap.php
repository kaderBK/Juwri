<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Hosts;

/**
 * P1-20 (J28): which platform origin a host is. Reads config/hosts.php only (no DB) — store hosts (verified
 * custom domains, platform subdomains) are recognised by the Storefront module's resolver, which marks the
 * request with {@see self::STORE_ATTRIBUTE}.
 *
 * Single mode (default): every platform host is the one APP_URL host; kinds are informational.
 * Split mode: marketing / app / admin / storefront are four distinct hosts.
 */
final class HostMap
{
    public const MARKETING = 'marketing';

    public const APP = 'app';

    public const ADMIN = 'admin';

    public const STOREFRONT = 'storefront';

    /** A verified custom domain or platform subdomain of one store. */
    public const STORE = 'store';

    public const UNKNOWN = 'unknown';

    /** Request attribute set by the store-address resolver: the store id the host belongs to. */
    public const STORE_ATTRIBUTE = 'juwri.store_host';

    /** @var array<string, \Closure(): iterable<string>> named sources of extra storefront hosts (see addStorefrontAliases) */
    private static array $aliasSources = [];

    /** @var array<string, \Closure(string): (int|null)> named store-host resolvers (see addStoreHostResolver) */
    private static array $storeHostResolvers = [];

    /**
     * A module adds hosts that serve the path-form storefront besides the storefront host (an install migrated from
     * another platform keeps that platform's host). Keyed by name: a provider booting again replaces its own source.
     * The source is called on every lookup, so it must re-check its own switch and return [] when off.
     *
     * @param  \Closure(): iterable<string>  $source
     */
    public static function addStorefrontAliases(string $name, \Closure $source): void
    {
        self::$aliasSources[$name] = $source;
    }

    /**
     * Drop everything a module registered under $name (aliases, store-host resolver, pending grace). Its provider
     * calls this at every boot before registering again, so a registration never outlives the application that made
     * it (a later test application, or the module switched off).
     */
    public static function forgetModuleSources(string $name): void
    {
        unset(self::$aliasSources[$name], self::$storeHostResolvers[$name], self::$pendingGrace[$name], self::$importedDomains[$name]);
    }

    /**
     * Extra storefront hosts registered by modules, lower-case, without `www.` twins. A failing source is skipped.
     *
     * @return list<string>
     */
    public static function storefrontAliases(): array
    {
        $hosts = [];
        foreach (self::$aliasSources as $source) {
            try {
                foreach ($source() as $host) {
                    $host = strtolower(rtrim(trim((string) $host), '.'));
                    if ($host !== '') {
                        $hosts[] = $host;
                    }
                }
            } catch (\Throwable) {
                // a broken source must not take the platform hosts down
            }
        }

        return array_values(array_unique($hosts));
    }

    /** Is $host (or its `www.` twin) a registered extra storefront host? */
    public static function isStorefrontAlias(string $host): bool
    {
        $host = strtolower(rtrim($host, '.'));
        $bare = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        $aliases = self::storefrontAliases();

        return $host !== '' && (in_array($host, $aliases, true) || in_array($bare, $aliases, true));
    }

    /**
     * Is $host a subdomain (any depth, other than the `www.` twin) of a registered extra storefront host? Such a host
     * serves a store only when a store resolver claims it; anything else under it is a 404, never the app.
     */
    public static function isUnderStorefrontAlias(string $host): bool
    {
        $host = strtolower(rtrim($host, '.'));
        foreach (self::storefrontAliases() as $alias) {
            if ($host !== 'www.'.$alias && str_ends_with($host, '.'.$alias)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Trusted-host patterns for the extra storefront hosts: the host, its `www.` twin and exactly ONE label below it
     * (a store subdomain). Deeper names are never trusted.
     *
     * @return list<string>
     */
    public static function storefrontAliasPatterns(): array
    {
        $out = [];
        foreach (self::storefrontAliases() as $alias) {
            $out[] = '^([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\\.)?'.preg_quote($alias).'$';
        }

        return $out;
    }

    /** @var array<string, \Closure(int): (int|null)> named pending-grace overrides (see addPendingGrace) */
    private static array $pendingGrace = [];

    /**
     * A module gives some pending (unverified) store_domains rows a longer grace before another store may claim the
     * host (imported rows of a migrated install). The closure returns hours for a row id, or null for the default.
     *
     * @param  \Closure(int): (int|null)  $grace
     */
    public static function addPendingGrace(string $name, \Closure $grace): void
    {
        self::$pendingGrace[$name] = $grace;
    }

    /** @var array<string, \Closure(int): bool> named "this store_domains row was imported" checks (see addImportedDomain) */
    private static array $importedDomains = [];

    /**
     * BL-036 R2: a module marks store_domains rows it imported from another platform; only those rows may prove control
     * with that platform's DNS challenge (hosts.legacy_verify_labels / hosts.legacy_txt_prefixes).
     *
     * @param  \Closure(int): bool  $check
     */
    public static function addImportedDomain(string $name, \Closure $check): void
    {
        self::$importedDomains[$name] = $check;
    }

    /** Was store_domains row $id imported by a module (a failing check counts as no)? */
    public static function isImportedDomain(int $id): bool
    {
        foreach (self::$importedDomains as $check) {
            try {
                if ($check($id) === true) {
                    return true;
                }
            } catch (\Throwable) {
                // a failing source never widens the proof
            }
        }

        return false;
    }

    /** The longest registered grace (hours) for store_domains row $id, or null when none applies. */
    public static function pendingGraceHours(int $id): ?int
    {
        $best = null;
        foreach (self::$pendingGrace as $grace) {
            try {
                $hours = $grace($id);
            } catch (\Throwable) {
                $hours = null;
            }
            if (is_int($hours) && $hours > 0) {
                $best = max($best ?? 0, $hours);
            }
        }

        return $best;
    }

    /**
     * A module resolves extra store hosts the store_domains verification does not cover (an imported platform
     * subdomain of a migrated install). The resolver returns the id of the store_domains row answering on the host,
     * or null; the Storefront resolver consults it only when no verified row matches. Keyed by name like the aliases.
     *
     * @param  \Closure(string): (int|null)  $resolver
     */
    public static function addStoreHostResolver(string $name, \Closure $resolver): void
    {
        self::$storeHostResolvers[$name] = $resolver;
    }

    /** The store_domains row id a registered resolver gives $host, or null. */
    public static function resolveExtraStoreHost(string $host): ?int
    {
        foreach (self::$storeHostResolvers as $resolver) {
            try {
                $id = $resolver($host);
            } catch (\Throwable) {
                $id = null;
            }
            if (is_int($id) && $id > 0) {
                return $id;
            }
        }

        return null;
    }

    public static function split(): bool
    {
        return config('hosts.mode') === 'split';
    }

    public static function hostOf(?string $url): string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }
        $host = parse_url(str_contains($url, '://') ? $url : 'https://'.$url, PHP_URL_HOST);

        return strtolower(rtrim((string) $host, '.'));
    }

    public static function appUrl(): string
    {
        return rtrim((string) (config('hosts.app_url') ?: config('app.url')), '/');
    }

    public static function appHost(): string
    {
        return self::hostOf(self::appUrl());
    }

    public static function adminHost(): string
    {
        return self::split() ? (self::hostOf(config('hosts.admin_url')) ?: self::appHost()) : self::appHost();
    }

    public static function marketingHost(): string
    {
        return self::split() ? (self::hostOf(config('hosts.marketing_url')) ?: self::appHost()) : self::appHost();
    }

    public static function storefrontHost(): string
    {
        return self::hostOf(self::storefrontUrl());
    }

    /** Base URL of the path-form storefront (`{base}/{slug}`). */
    public static function storefrontUrl(): string
    {
        $url = self::split() ? (string) config('hosts.storefront_url') : '';

        return rtrim($url !== '' ? $url : self::appUrl(), '/');
    }

    public static function storefrontScheme(): string
    {
        return (string) (parse_url(self::storefrontUrl(), PHP_URL_SCHEME) ?: 'https');
    }

    public static function adminUrl(): string
    {
        $url = self::split() ? (string) config('hosts.admin_url') : '';

        return rtrim($url !== '' ? $url : self::appUrl(), '/');
    }

    public static function marketingUrl(): string
    {
        $url = self::split() ? (string) config('hosts.marketing_url') : '';

        return rtrim($url !== '' ? $url : self::appUrl(), '/');
    }

    /**
     * The platform's own hosts (not store hosts), de-duplicated.
     *
     * @return list<string>
     */
    public static function platformHosts(): array
    {
        return array_values(array_unique(array_filter([
            self::appHost(), self::adminHost(), self::marketingHost(), self::storefrontHost(),
        ])));
    }

    /**
     * Kind of a platform host. A host that is none of the platform hosts is UNKNOWN here (a store host is
     * recognised by the Storefront resolver). In single mode every platform host is APP.
     */
    public static function kind(string $host): string
    {
        $host = strtolower(rtrim($host, '.'));
        $bare = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        if (! self::split()) {
            if (in_array($host, [self::appHost(), 'www.'.self::appHost()], true)) {
                return self::APP;
            }

            // Single mode too: an extra storefront host never serves the dashboard (EnforceHostSplit).
            return self::isStorefrontAlias($host) ? self::STOREFRONT : self::UNKNOWN;
        }
        // Order matters when an operator reuses a host: admin beats app beats storefront beats marketing.
        foreach ([self::ADMIN => self::adminHost(), self::APP => self::appHost(),
            self::STOREFRONT => self::storefrontHost(), self::MARKETING => self::marketingHost()] as $kind => $h) {
            if ($h !== '' && ($host === $h || ($kind === self::MARKETING && $bare === $h))) {
                return $kind;
            }
        }

        // An extra storefront host (module-registered) is a storefront origin: store pages, no dashboard.
        return self::isStorefrontAlias($host) ? self::STOREFRONT : self::UNKNOWN;
    }

    /**
     * The label when $host is exactly one label under the storefront host (`{label}.<storefront host>`).
     */
    public static function storefrontSubdomainLabel(string $host): ?string
    {
        $base = self::storefrontHost();
        $host = strtolower(rtrim($host, '.'));
        if ($base === '' || ! str_ends_with($host, '.'.$base)) {
            return null;
        }
        $label = substr($host, 0, -strlen('.'.$base));

        return ($label !== '' && ! str_contains($label, '.')) ? $label : null;
    }

    public static function isReservedLabel(string $label): bool
    {
        return in_array(strtolower($label), array_map('strtolower', (array) config('hosts.reserved_subdomains', [])), true);
    }

    /**
     * Configuration problems for the admin health page (J28: single-host installs get a warning).
     *
     * @return list<string>
     */
    public static function warnings(): array
    {
        if (! self::split()) {
            return ['Single-host mode: the storefronts, the owner dashboard and the superadmin panel share one origin. Set APP_ADMIN_URL, STOREFRONT_URL, MARKETING_URL and HOST_MODE=split to separate them.'];
        }
        $out = [];
        foreach (['APP_ADMIN_URL' => config('hosts.admin_url'), 'STOREFRONT_URL' => config('hosts.storefront_url'), 'MARKETING_URL' => config('hosts.marketing_url')] as $env => $url) {
            if (self::hostOf($url) === '') {
                $out[] = "HOST_MODE=split but {$env} is not set.";
            }
        }
        $hosts = [self::appHost(), self::hostOf(config('hosts.admin_url')), self::hostOf(config('hosts.storefront_url')), self::hostOf(config('hosts.marketing_url'))];
        $hosts = array_filter($hosts);
        if (count($hosts) !== count(array_unique($hosts))) {
            $out[] = 'HOST_MODE=split but two origins share a host.';
        }

        return $out;
    }
}
