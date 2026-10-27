<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Juwri\Core\Http\Hosts\HostMap;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Models\Store;
use Juwri\Modules\Storefront\Services\StoreAddressResolver;
use Juwri\Modules\Storefront\Services\StoreDomains;

/**
 * One host a store answers on (P1-20, J28): an owner's custom domain or a platform subdomain. The path form
 * (`/{slug}` on the storefront host) is implicit and has no row. Resolve and build addresses through
 * {@see StoreAddressResolver} / store_url(); manage rows through
 * {@see StoreDomains} (primary switch, verification, reserved names).
 *
 * `domain` and `verified` are kept as virtual attributes (read + write, appended to JSON) so the owner and
 * reseller JSON contracts of the old restaurant_domains rows stay unchanged.
 *
 * @property int $id
 * @property int|null $account_id
 * @property int $store_id
 * @property string $type
 * @property string $host
 * @property bool $is_primary
 * @property Carbon|null $verified_at
 * @property string|null $verification_token
 * @property string $ssl_status
 * @property Carbon|null $dns_checked_at
 * @property Carbon|null $dns_failing_since
 * @property-read string $domain
 * @property-read bool $verified
 */
class StoreDomain extends Model
{
    use BelongsToAccount;

    public const TYPE_CUSTOM = 'custom';

    public const TYPE_SUBDOMAIN = 'subdomain';

    protected $table = 'store_domains';

    /** Open like the old model: the virtual `domain` / `verified` names must pass fill() (a non-empty guard list drops non-columns). */
    protected $guarded = [];

    /** @var array<string, mixed> DB defaults, so a fresh model reads the same as a stored one. */
    protected $attributes = ['type' => self::TYPE_CUSTOM, 'is_primary' => false, 'ssl_status' => 'pending'];

    /** @var list<string> */
    protected $appends = ['domain', 'verified'];

    protected static function booted(): void
    {
        // The resolver memoises host → store for the request; any change to a row invalidates it.
        $flush = static function (): void {
            if (app()->resolved(StoreAddressResolver::class)) {
                app(StoreAddressResolver::class)->flush();
            }
            Cache::forget('trusted_custom_domains'); // TrustHosts' 60 s list
        };
        static::saved($flush);
        static::deleted($flush);

        // The first verified custom domain of a store becomes its primary (what effective_public_url() always
        // treated as the canonical origin). Later ones — and a store the owner switched to the path form —
        // keep their choice: only the transition to verified promotes, and only when no primary exists.
        static::saving(function (self $domain): void {
            $becameVerified = $domain->verified_at !== null
                && (! $domain->exists || $domain->getOriginal('verified_at') === null);
            if (! $becameVerified || $domain->is_primary || ! $domain->store_id || $domain->type === self::TYPE_SUBDOMAIN) {
                return;
            }
            $domain->is_primary = ! static::query()->withoutGlobalScopes()
                ->where('store_id', $domain->store_id)->where('is_primary', true)->whereNotNull('verified_at')
                ->when($domain->exists, fn ($q) => $q->whereKeyNot($domain->getKey()))
                ->exists();
        });
    }

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'verified_at' => 'datetime',
            'dns_checked_at' => 'datetime',
            'dns_failing_since' => 'datetime',
        ];
    }

    /** @return BelongsTo<Store, $this> */
    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'store_id');
    }

    /** Legacy name of `host`. */
    /** @return Attribute<string|null, string> */
    protected function domain(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->attributes['host'] ?? null,
            set: fn ($value) => ['host' => self::normalizeHost((string) $value)],
        );
    }

    /** Legacy boolean view of `verified_at`; setting true keeps an existing timestamp. */
    /** @return Attribute<bool, mixed> */
    protected function verified(): Attribute
    {
        return Attribute::make(
            get: fn () => ($this->attributes['verified_at'] ?? null) !== null,
            set: fn ($value) => ['verified_at' => $value
                ? ($this->attributes['verified_at'] ?? now())
                : null],
        );
    }

    /** @return Attribute<string, string> */
    protected function host(): Attribute
    {
        return Attribute::make(set: fn ($value) => self::normalizeHost((string) $value));
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVerified(Builder $query): Builder
    {
        return $query->whereNotNull($this->qualifyColumn('verified_at'));
    }

    /**
     * Lower-case, trimmed, no trailing dot, no port; IDN labels converted to punycode when intl is available.
     */
    /**
     * An unverified row whose hold on its host has lapsed (config hosts.pending_grace_hours, or a module's longer
     * grace for its rows — a migrated install's imported domains). Verified rows never lapse.
     */
    public function isPendingExpired(): bool
    {
        $hours = max(0, (int) config('hosts.pending_grace_hours', 72));
        $hours = max($hours, (int) ($this->exists ? HostMap::pendingGraceHours((int) $this->id) : 0));

        return ! $this->isVerified() && $this->created_at !== null && $this->created_at->lte(now()->subHours($hours));
    }

    /** BL-036 R2: the cache-lock key every claim on $host (and its www / bare twin) takes, store or reseller. */
    public static function claimLockKey(string $host): string
    {
        $host = self::normalizeHost($host);

        return 'domain-claim:'.(str_starts_with($host, 'www.') ? substr($host, 4) : $host);
    }

    /** @return Builder<self> rows on $host or its www / bare twin (any store, no tenant scope) */
    public static function hostRows(string $host): Builder
    {
        $host = self::normalizeHost($host);
        $bare = str_starts_with($host, 'www.') ? substr($host, 4) : $host;

        return self::query()->withoutGlobalScopes()->whereIn('host', array_unique([$host, $bare, 'www.'.$bare]));
    }

    /**
     * BL-036 R2: does a store hold $host (or its www / bare twin)? A verified row always does; an unverified one only
     * inside its grace period, so a stale request cannot squat a host for good. With $verifiedOnly, only verified
     * rows count (the last-moment check before a reseller domain goes live).
     */
    public static function hostHeld(string $host, bool $verifiedOnly = false): bool
    {
        return self::hostRows($host)->get()
            ->contains(fn (self $row) => $row->isVerified() || (! $verifiedOnly && ! $row->isPendingExpired()));
    }

    public static function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host));
        $host = rtrim($host, '.');
        if (str_contains($host, ':') && ! str_starts_with($host, '[')) {
            $host = explode(':', $host, 2)[0];
        }
        if ($host !== '' && preg_match('/[^\x20-\x7e]/', $host) && function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                $host = strtolower($ascii);
            }
        }

        return $host;
    }

    /**
     * Resolve a verified host to its store, or null.
     * Unverified rows never resolve.
     */
    public static function resolve(string $host): ?Store
    {
        $host = self::normalizeHost($host);
        if ($host === '') {
            return null;
        }
        $storeId = static::query()->withoutGlobalScopes()->verified()->where('host', $host)->value('store_id');

        return $storeId ? Store::find($storeId) : null;
    }
}
