<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Juwri\Core\Contracts\Tenancy\ResellerContext;
use Juwri\Core\Http\Hosts\HostMap;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\Tenancy\TenantContext;
use Juwri\Modules\Storefront\Models\StoreDomain;

/**
 * P1-20 (J28): writes to `store_domains` — request a custom domain, claim a platform subdomain, verify, switch
 * the primary address. Every write is scoped by an explicit store id; the one-primary-per-store rule is kept
 * in a transaction under a lock on the store row (portable; no partial unique index on MySQL).
 */
final class StoreDomains
{
    public const HOST_PATTERN = '/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    public const LABEL_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{1,61}[a-z0-9])?$/';

    /**
     * Why $host cannot be registered as a custom domain, or null when it can.
     */
    public static function customHostProblem(string $host): ?string
    {
        $host = StoreDomain::normalizeHost($host);
        if ($host === '') {
            return 'invalid';
        }
        foreach ([...HostMap::platformHosts(), ...HostMap::storefrontAliases()] as $platform) {
            // A platform host or anything under it is never an owner's domain (takeover of a platform
            // subdomain through the custom-domain form).
            if ($platform !== '' && ($host === $platform || str_ends_with($host, '.'.$platform))) {
                return 'platform';
            }
        }
        if (! preg_match(self::HOST_PATTERN, $host)) {
            return 'invalid';
        }
        // BL-036 R2: a reseller's live white-label domain, or one it has requested (until the request goes stale), is
        // another tenant's host, never a store's.
        if (app(ResellerContext::class)->holdsDomain($host)) {
            return 'taken';
        }
        // Squatting: a host another store verified is never available; an unverified request holds its
        // host only for the grace period (config hosts.pending_grace_hours), so a squatter cannot keep the
        // real owner out for good. Expired pending rows are purged by requestCustom().
        // BL-043: a host is unique platform-wide, so the lookup sees every account (bypass also lifts PostgreSQL RLS).
        if (TenantContext::bypass(fn (): bool => StoreDomain::hostHeld($host))) {
            return 'taken';
        }

        return null;
    }

    /**
     * Rows on $host or its www / bare twin, in EVERY account: a host is unique platform-wide, so callers run the
     * query under TenantContext::bypass() (BL-043: withoutGlobalScopes() alone does not lift PostgreSQL RLS).
     *
     * @return Builder<StoreDomain>
     */
    private static function hostRows(string $host): Builder
    {
        return StoreDomain::hostRows($host);
    }

    private static function pendingExpired(StoreDomain $row): bool
    {
        return $row->isPendingExpired();
    }

    /** Why $label cannot be claimed as a platform subdomain, or null when it can. */
    public static function subdomainProblem(string $label): ?string
    {
        $label = strtolower(trim($label));
        if (! preg_match(self::LABEL_PATTERN, $label) || str_contains($label, '--')) {
            return 'invalid';
        }
        if (HostMap::isReservedLabel($label)) {
            return 'reserved';
        }
        // R4a: a reseller's {subdomain}.{base} address may sit on the same host (another tenant's host).
        if (app(ResellerContext::class)->holdsDomain($label.'.'.HostMap::storefrontHost())) {
            return 'taken';
        }
        if (TenantContext::bypass(fn (): bool => StoreDomain::query()->withoutGlobalScopes()->where('host', $label.'.'.HostMap::storefrontHost())->exists())) {
            return 'taken';
        }

        return null;
    }

    public static function newToken(): string
    {
        return 'site-verify-'.Str::lower(Str::random(32));
    }

    /** A custom domain in the pending state (not served until verified). Caller checked customHostProblem(). */
    public static function requestCustom(Store $store, string $host): StoreDomain
    {
        // Free the host from expired, never-verified requests (the unique host index would refuse the insert).
        TenantContext::bypass(fn () => self::hostRows($host)->where('type', StoreDomain::TYPE_CUSTOM)->whereNull('verified_at')->get()
            ->filter(fn (StoreDomain $row) => self::pendingExpired($row))->each->delete());

        return StoreDomain::create([
            'store_id' => $store->id,
            'type' => StoreDomain::TYPE_CUSTOM,
            'host' => $host,
            'is_primary' => false,
            'verified_at' => null,
            'verification_token' => self::newToken(),
            'ssl_status' => 'pending',
        ]);
    }

    /**
     * A platform subdomain ({label}.<storefront host>) — platform-controlled DNS, so live at once.
     *
     * @throws \InvalidArgumentException when the label is not available
     */
    public static function claimSubdomain(Store $store, string $label, bool $primary = false): StoreDomain
    {
        $label = strtolower(trim($label));
        if (($problem = self::subdomainProblem($label)) !== null) {
            throw new \InvalidArgumentException("subdomain {$label}: {$problem}");
        }
        $row = StoreDomain::create([
            'store_id' => $store->id,
            'type' => StoreDomain::TYPE_SUBDOMAIN,
            'host' => $label.'.'.HostMap::storefrontHost(),
            'is_primary' => false,
            'verified_at' => now(),
            'verification_token' => null,
            'ssl_status' => 'active',
        ]);
        if ($primary) {
            self::makePrimary($row);
        }

        return $row->refresh();
    }

    /**
     * Run the DNS proof for a pending custom domain. On success the row is verified and, when the store has
     * no primary yet, becomes its primary (the old "first verified domain is canonical" behaviour).
     */
    public static function verify(StoreDomain $domain, DomainVerifier $verifier): bool
    {
        if ($domain->isVerified()) {
            return true;
        }
        // BL-036 R2: an imported row may also prove control with the source platform's challenge (hosts.legacy_*).
        if (! $verifier->verify($domain->host, (string) $domain->verification_token, HostMap::isImportedDomain((int) $domain->id))) {
            return false;
        }

        // BL-036 R2 re-review 3: a reseller may hold the host (live, or a request that is not stale); re-check under
        // the host's claim lock before the row is marked verified.
        return Cache::lock(StoreDomain::claimLockKey($domain->host), 30)->block(max(1, (int) config('hosts.claim_lock_wait_seconds', 10)), function () use ($domain): bool {
            if (app(ResellerContext::class)->holdsDomain($domain->host)) {
                return false;
            }
            self::markVerified($domain);

            return true;
        });
    }

    public static function markVerified(StoreDomain $domain): void
    {
        DB::transaction(function () use ($domain): void {
            DB::table('stores')->where('id', $domain->store_id)->lockForUpdate()->value('id');
            $domain->forceFill(['verified_at' => $domain->verified_at ?? now(), 'ssl_status' => 'active'])->save();
            $hasPrimary = StoreDomain::query()->withoutGlobalScopes()->where('store_id', $domain->store_id)
                ->where('is_primary', true)->whereNotNull('verified_at')->where('id', '!=', $domain->id)->exists();
            if (! $hasPrimary) {
                self::setPrimary($domain->store_id, $domain->id);
            }
        });
    }

    /**
     * P6-241: the daily DNS recheck of one verified custom domain. Passing clears the failure clock; failing starts it;
     * failing for hosts.release_after_days in a row releases the row (see release()). A resolver outage (null) changes
     * nothing. Returns 'ok' | 'failing' | 'released' | 'skipped'.
     */
    public static function recheck(StoreDomain $domain, DomainVerifier $verifier): string
    {
        if ($domain->type !== StoreDomain::TYPE_CUSTOM || ! $domain->isVerified()) {
            return 'skipped';
        }
        $pointed = $verifier->stillPointed($domain->host, (string) $domain->verification_token);
        if ($pointed === null) {
            return 'skipped';
        }
        if ($pointed) {
            $domain->forceFill(['dns_checked_at' => now(), 'dns_failing_since' => null])->save();

            return 'ok';
        }
        $since = $domain->dns_failing_since ?? now();
        $domain->forceFill(['dns_checked_at' => now(), 'dns_failing_since' => $since])->save();
        if ($since->lte(now()->subDays(max(1, (int) config('hosts.release_after_days', 14))))) {
            self::release($domain, 'dns_lapsed');

            return 'released';
        }

        return 'failing';
    }

    /**
     * P6-241: give a custom domain up (DNS lapsed, the store removed it). The row is deleted, so the unique host is free for
     * another store — which still has to prove ownership with its own TXT token before the host serves it.
     */
    public static function release(StoreDomain $domain, string $reason): void
    {
        DB::transaction(function () use ($domain, $reason): void {
            DB::table('stores')->where('id', $domain->store_id)->lockForUpdate()->value('id');
            $wasPrimary = (bool) $domain->is_primary;
            $domain->delete();
            if ($wasPrimary) {
                self::setPrimary((int) $domain->store_id, null); // back to the path form
            }
            Log::info('store domain released', ['store_id' => $domain->store_id, 'host' => $domain->host, 'reason' => $reason]);
        });
    }

    /** Make a verified row the store's primary address (clears the others). */
    public static function makePrimary(StoreDomain $domain): void
    {
        if (! $domain->isVerified()) {
            throw new \InvalidArgumentException('Only a verified address can be primary.');
        }
        DB::transaction(function () use ($domain): void {
            DB::table('stores')->where('id', $domain->store_id)->lockForUpdate()->value('id');
            self::setPrimary($domain->store_id, $domain->id);
        });
    }

    /** Make the path form (`/{slug}`) the store's primary address. */
    public static function usePathAsPrimary(int $storeId): void
    {
        DB::transaction(function () use ($storeId): void {
            DB::table('stores')->where('id', $storeId)->lockForUpdate()->value('id');
            self::setPrimary($storeId, null);
        });
    }

    private static function setPrimary(int $storeId, ?int $domainId): void
    {
        StoreDomain::query()->withoutGlobalScopes()->where('store_id', $storeId)
            ->when($domainId, fn ($q) => $q->where('id', '!=', $domainId))
            ->where('is_primary', true)->update(['is_primary' => false, 'updated_at' => now()]);
        if ($domainId) {
            StoreDomain::query()->withoutGlobalScopes()->whereKey($domainId)->update(['is_primary' => true, 'updated_at' => now()]);
        }
        if (app()->resolved(StoreAddressResolver::class)) {
            app(StoreAddressResolver::class)->flush();
        }
        Cache::forget('trusted_custom_domains');
    }
}
