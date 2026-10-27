<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Editions\Limits;

/**
 * THE store/branch limit policy (owner 2026-10-05: one central class, no scattered checks, no obfuscation — the AGPL
 * lets anyone change it; the limit is a product feature of the Community code, not an extra licence restriction).
 *
 * Pure functions only — no database, container or clock — so a juwri-hosted service can run the very same decision
 * server-side from a licence's edition + `max_branches` and the counts it sees:
 *
 *   $limits   = StoreLimitRules::limitsFor($edition, $maxBranches);            // 'community' | 'chain' | 'enterprise' | …
 *   $decision = StoreLimitRules::newStore($limits, $storesOfThatType, $branchesOfTheBusiness);
 *   $decision = StoreLimitRules::newBranches($limits, $branchesOfTheStore, $branchesOfTheBusiness);
 *   $byStore  = StoreLimitRules::allowance($limits, $storesSeenFirstSeenFirst);   // juwri-hosted services
 *
 * Inside the app every creation path reaches this class through {@see StoreLimitGuard} (it counts, this decides).
 *
 * - Community: one store of each store type per business, each with up to three branches; unlimited staff.
 * - Chain licence: no per-type / per-store cap; at most `max_branches` branches across the business (a new store
 *   brings its first branch, so it needs one free branch too). The licensed count replaces the Community limits (no
 *   "Community floor": per-store Community allowances on top of the licensed total would let every store grow to three
 *   branches past the count).
 * - Enterprise / Platform / development licence: unlimited, or `max_branches` in total when a v2 licence sets it.
 * - Anything else (unknown edition, chain without max_branches): Community.
 */
final class StoreLimitRules
{
    public const COMMUNITY_STORES_PER_TYPE = 1;

    public const COMMUNITY_BRANCHES_PER_STORE = 3;

    /** Licence editions whose limits are open unless the licence sets `max_branches`. */
    public const OPEN_EDITIONS = ['enterprise', 'platform', 'development'];

    public static function community(): StoreLimits
    {
        return new StoreLimits('community', self::COMMUNITY_STORES_PER_TYPE, self::COMMUNITY_BRANCHES_PER_STORE, null);
    }

    /** The limits of a licence edition (+ its `max_branches`, null when the licence has none). */
    public static function limitsFor(string $edition, ?int $maxBranches): StoreLimits
    {
        $maxBranches = $maxBranches !== null && $maxBranches >= 1 ? $maxBranches : null;
        if ($edition === 'chain') {
            return $maxBranches === null
                ? self::community()
                : new StoreLimits('chain', null, null, $maxBranches);
        }
        if (in_array($edition, self::OPEN_EDITIONS, true)) {
            return $maxBranches === null
                ? new StoreLimits($edition, null, null, null)
                : new StoreLimits($edition, null, null, $maxBranches);
        }

        return self::community();
    }

    /**
     * May the business get one more store of a store type?
     *
     * @param  int  $storesOfType  stores of that type the business has now (the store itself excluded when it moves)
     * @param  int  $branchesTotal  branches of the whole business now
     * @param  int  $newBranches  branches the store brings (1 for a new store — its first branch; a store that moves
     *                            business brings its own; 0 for a store that only changes type)
     * @param  bool  $newStore  false for an existing store that changes type or business: its own branch count is
     *                          existing data and is not held against the per-store cap
     */
    public static function newStore(StoreLimits $limits, int $storesOfType, int $branchesTotal, int $newBranches = 1, bool $newStore = true): LimitDecision
    {
        if ($limits->storesPerType !== null && $storesOfType + 1 > $limits->storesPerType) {
            return LimitDecision::refuse($limits->edition, LimitDecision::STORES_PER_TYPE, $limits->storesPerType, $storesOfType);
        }
        if ($newStore && $limits->branchesPerStore !== null && $newBranches > $limits->branchesPerStore) {
            return LimitDecision::refuse($limits->edition, LimitDecision::BRANCHES_PER_STORE, $limits->branchesPerStore, 0);
        }
        if ($limits->branchesTotal !== null && $newBranches > 0 && $branchesTotal + $newBranches > $limits->branchesTotal) {
            return LimitDecision::refuse($limits->edition, LimitDecision::BRANCHES_TOTAL, $limits->branchesTotal, $branchesTotal);
        }

        return LimitDecision::allow($limits->edition);
    }

    /**
     * May a store get $count more branches?
     *
     * @param  int  $branchesInStore  branches of that store now
     * @param  int  $branchesTotal  branches of the whole business now
     */
    public static function newBranches(StoreLimits $limits, int $branchesInStore, int $branchesTotal, int $count = 1): LimitDecision
    {
        if ($count <= 0) {
            return LimitDecision::allow($limits->edition);
        }
        if ($limits->branchesPerStore !== null && $branchesInStore + $count > $limits->branchesPerStore) {
            return LimitDecision::refuse($limits->edition, LimitDecision::BRANCHES_PER_STORE, $limits->branchesPerStore, $branchesInStore);
        }
        if ($limits->branchesTotal !== null && $branchesTotal + $count > $limits->branchesTotal) {
            return LimitDecision::refuse($limits->edition, LimitDecision::BRANCHES_TOTAL, $limits->branchesTotal, $branchesTotal);
        }

        return LimitDecision::allow($limits->edition);
    }

    /**
     * Which of a business's stores and branches fall inside the limits when they are taken in the order they were
     * first seen — a juwri-hosted service passes the stores and branches it has SEEN for one installation, so the ones
     * that came first stay served and only those past the limit are refused (owner 2026-10-05: a store past the limit
     * keeps trading locally; the service refuses it). One order across stores AND branches: a branch seen later never
     * pushes out a store seen earlier. Same decisions as {@see newStore()} / {@see newBranches()}: a store brings its
     * first branch (the first branch ref seen for it, or its main branch when none is ever named); a refused store or
     * branch uses none of the allowance.
     *
     * @param  list<array{ref: string, type: string, seq: int, branches: array<array-key, int>}>  $stores  `seq`: when the
     *                                                                                                     store was first seen; `branches`: branch ref => when it was first seen
     * @return array<string, array{store: LimitDecision, branches: array<string, LimitDecision>}> by store ref
     */
    public static function allowance(StoreLimits $limits, array $stores): array
    {
        $events = [];
        foreach ($stores as $i => $store) {
            $events[] = [$store['seq'], 0, $i, ''];
            foreach ($store['branches'] as $ref => $seq) {
                $events[] = [max($seq, $store['seq']), 1, $i, (string) $ref];
            }
        }
        usort($events, fn (array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

        $ofType = [];
        $total = 0;
        $inStore = [];
        $decisions = [];
        $branches = [];
        foreach ($events as [, $isBranch, $i, $branchRef]) {
            $store = $stores[$i];
            if ($isBranch === 0) {
                $decision = self::newStore($limits, $ofType[$store['type']] ?? 0, $total);
                $decisions[$i] = $decision;
                $branches[$i] = [];
                if ($decision->allowed) {
                    $ofType[$store['type']] = ($ofType[$store['type']] ?? 0) + 1;
                    $total++;
                    $inStore[$i] = 0; // branches named so far (the first one is the branch the store brought)
                }

                continue;
            }
            if ($decisions[$i]->refused()) {
                $branches[$i][$branchRef] = $decisions[$i]; // a refused store refuses its branches

                continue;
            }
            if ($inStore[$i] === 0) {
                $branches[$i][$branchRef] = $decisions[$i]; // counted with the store
                $inStore[$i] = 1;

                continue;
            }
            $decision = self::newBranches($limits, $inStore[$i], $total);
            if ($decision->allowed) {
                $inStore[$i]++;
                $total++;
            }
            $branches[$i][$branchRef] = $decision;
        }

        $out = [];
        foreach ($stores as $i => $store) {
            $out[$store['ref']] = ['store' => $decisions[$i], 'branches' => $branches[$i]];
        }

        return $out;
    }
}
