<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Editions\Limits;

use App\Services\Verticals\VerticalRegistry;
use Closure;
use Juwri\Core\Contracts\Editions\StoreLimitPolicy;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\Tenancy\TenantContext;

/**
 * Applies {@see StoreLimitRules} to this installation: counts the business's stores and branches and asks the rules
 * (it holds no limit of its own; the limits come from the bound {@see StoreLimitPolicy}).
 *
 * Enforced server side on the models themselves — Store and Branch call {@see guardStore()} / {@see guardBranch()}
 * when a row is created or moves (store type, business, branch → another store), so every creation path is covered:
 * the owner dashboard wizard, duplicate / duplicate-as-another-vertical, AI Studio publish, admin, the API, transfers,
 * and anything added later. Paths that write rows without Eloquent (the admin backup restore) call
 * {@see assertNewStore()} / {@see assertNewBranches()} first; controllers also ask before they start so the owner gets
 * the message in the page's own format. Editing an existing store or branch is never limited (over-limit data from
 * before keeps working); only growth past the limit is refused, with {@see StoreLimitReached}.
 *
 * "Business" = the store's account (one Community install runs one business).
 */
final class StoreLimitGuard
{
    private static int $bypass = 0;

    /**
     * Runs $callback without the limits — for the product's own demo/fixture seeders only (install-time data, not
     * owner growth). Never used on a request path.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function bypass(Closure $callback): mixed
    {
        self::$bypass++;
        try {
            return $callback();
        } finally {
            self::$bypass--;
        }
    }

    /**
     * `artisan db:seed` (and `migrate --seed`): the product's demo/fixture seeders run without the limits — see
     * CoreServiceProvider. Owner actions never pass through here.
     */
    public static function seeding(bool $on): void
    {
        self::$bypass = max(0, self::$bypass + ($on ? 1 : -1));
    }

    public static function bypassed(): bool
    {
        return self::$bypass > 0;
    }

    public function limits(): StoreLimits
    {
        return app(StoreLimitPolicy::class)->limits();
    }

    /**
     * May the business ($accountId) get a store of $storeType? $exceptStoreId: an existing store that changes type or
     * business (not counted against itself; its branches move with it).
     */
    public function checkNewStore(?int $accountId, ?string $storeType, ?int $exceptStoreId = null): LimitDecision
    {
        $limits = $this->limits();
        if (self::bypassed() || $limits->unlimited() || ! $accountId) {
            return LimitDecision::allow($limits->edition);
        }
        $type = self::normaliseType($storeType);

        return TenantContext::bypass(function () use ($limits, $accountId, $type, $exceptStoreId): LimitDecision {
            $types = Store::query()->withoutGlobalScopes()->where('account_id', $accountId)
                ->when($exceptStoreId, fn ($q) => $q->whereKeyNot($exceptStoreId))
                ->pluck('store_type');
            $ofType = $types->filter(fn ($t) => self::normaliseType($t === null ? null : (string) $t) === $type)->count();
            $total = $this->branchesOfAccount($accountId, $exceptStoreId);
            if ($exceptStoreId === null) {
                return StoreLimitRules::newStore($limits, $ofType, $total);
            }
            $own = Branch::query()->withoutGlobalScopes()->where('store_id', $exceptStoreId)->count();

            return StoreLimitRules::newStore($limits, $ofType, $total, $own, newStore: false);
        });
    }

    /** May store $storeId get $count more branches? $exceptBranchId: a branch moving into it (not counted twice). */
    public function checkNewBranches(int $storeId, int $count = 1, ?int $exceptBranchId = null): LimitDecision
    {
        $limits = $this->limits();
        if (self::bypassed() || $limits->unlimited() || $count <= 0) {
            return LimitDecision::allow($limits->edition);
        }

        return TenantContext::bypass(function () use ($limits, $storeId, $count, $exceptBranchId): LimitDecision {
            $inStore = Branch::query()->withoutGlobalScopes()->where('store_id', $storeId)
                ->when($exceptBranchId, fn ($q) => $q->whereKeyNot($exceptBranchId))->count();
            $accountId = Store::query()->withoutGlobalScopes()->whereKey($storeId)->value('account_id');
            $total = $accountId ? $this->branchesOfAccount((int) $accountId, null, $exceptBranchId) : $inStore;

            return StoreLimitRules::newBranches($limits, $inStore, $total, $count);
        });
    }

    /**
     * A set of whole stores arriving at once (backup restore, account import): each store with its type and its
     * number of branches, checked one after another against the business's current counts.
     *
     * @param  list<array{type: string|null, branches: int}>  $stores
     */
    public function checkStoreSet(?int $accountId, array $stores): LimitDecision
    {
        $limits = $this->limits();
        if (self::bypassed() || $limits->unlimited() || $stores === []) {
            return LimitDecision::allow($limits->edition);
        }

        return TenantContext::bypass(function () use ($limits, $accountId, $stores): LimitDecision {
            $ofType = [];
            $total = 0;
            if ($accountId) {
                foreach (Store::query()->withoutGlobalScopes()->where('account_id', $accountId)->pluck('store_type') as $t) {
                    $key = self::normaliseType($t === null ? null : (string) $t);
                    $ofType[$key] = ($ofType[$key] ?? 0) + 1;
                }
                $total = $this->branchesOfAccount($accountId);
            }
            foreach ($stores as $store) {
                $key = self::normaliseType($store['type']);
                $branches = max(1, $store['branches']); // a store always has its first branch
                $decision = StoreLimitRules::newStore($limits, $ofType[$key] ?? 0, $total, $branches);
                if ($decision->refused()) {
                    return $decision;
                }
                $ofType[$key] = ($ofType[$key] ?? 0) + 1;
                $total += $branches;
            }

            return LimitDecision::allow($limits->edition);
        });
    }

    /**
     * @param  list<array{type: string|null, branches: int}>  $stores
     *
     * @throws StoreLimitReached
     */
    public function assertStoreSet(?int $accountId, array $stores): void
    {
        $decision = $this->checkStoreSet($accountId, $stores);
        if ($decision->refused()) {
            throw new StoreLimitReached($decision);
        }
    }

    /** @throws StoreLimitReached */
    public function assertNewStore(?int $accountId, ?string $storeType, ?int $exceptStoreId = null): void
    {
        $decision = $this->checkNewStore($accountId, $storeType, $exceptStoreId);
        if ($decision->refused()) {
            throw new StoreLimitReached($decision);
        }
    }

    /** @throws StoreLimitReached */
    public function assertNewBranches(int $storeId, int $count = 1, ?int $exceptBranchId = null): void
    {
        $decision = $this->checkNewBranches($storeId, $count, $exceptBranchId);
        if ($decision->refused()) {
            throw new StoreLimitReached($decision);
        }
    }

    /**
     * Store model hook (creating / updating): a new store, or an existing one whose type or business changes.
     *
     * @throws StoreLimitReached
     */
    public static function guardStore(Store $store): void
    {
        if (self::bypassed()) {
            return;
        }
        if (! $store->exists) {
            app(self::class)->assertNewStore($store->account_id ? (int) $store->account_id : null, $store->store_type);

            return;
        }
        $typeChanged = $store->isDirty('store_type')
            && self::normaliseType($store->store_type) !== self::normaliseType($store->getOriginal('store_type'));
        if ($typeChanged || ($store->isDirty('account_id') && $store->account_id)) {
            app(self::class)->assertNewStore($store->account_id ? (int) $store->account_id : null, $store->store_type, (int) $store->getKey());
        }
    }

    /**
     * Branch model hook (creating / updating): a new branch, or one that moves to another store.
     *
     * @throws StoreLimitReached
     */
    public static function guardBranch(Branch $branch): void
    {
        if (self::bypassed() || ! $branch->store_id) {
            return;
        }
        if (! $branch->exists) {
            app(self::class)->assertNewBranches((int) $branch->store_id);
        } elseif ($branch->isDirty('store_id')) {
            app(self::class)->assertNewBranches((int) $branch->store_id, 1, (int) $branch->getKey());
        }
    }

    /** A store's type for counting: empty or unknown values count as the default vertical (as the app renders them). */
    public static function normaliseType(?string $type): string
    {
        $registry = app(VerticalRegistry::class);

        return $type !== null && $type !== '' && $registry->exists($type) ? $type : $registry->default();
    }

    private function branchesOfAccount(int $accountId, ?int $exceptStoreId = null, ?int $exceptBranchId = null): int
    {
        return Branch::query()->withoutGlobalScopes()
            ->whereIn('store_id', Store::query()->withoutGlobalScopes()->select('id')->where('account_id', $accountId))
            ->when($exceptStoreId, fn ($q) => $q->where('store_id', '!=', $exceptStoreId))
            ->when($exceptBranchId, fn ($q) => $q->whereKeyNot($exceptBranchId))
            ->count();
    }
}
