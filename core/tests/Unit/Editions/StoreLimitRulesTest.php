<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Unit\Editions;

use Juwri\Core\Editions\Limits\LimitDecision;
use Juwri\Core\Editions\Limits\StoreLimitRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The one central store/branch limit policy as pure functions (edition + counts → allowed / refused with a reason):
 * no app, no database — the same calls a juwri-hosted service can make server-side.
 */
class StoreLimitRulesTest extends TestCase
{
    public function test_community_limits(): void
    {
        $l = StoreLimitRules::limitsFor('community', null);
        $this->assertSame(['edition' => 'community', 'stores_per_type' => 1, 'branches_per_store' => 3, 'branches_total' => null], $l->toArray());

        $this->assertTrue(StoreLimitRules::newStore($l, 0, 50)->allowed, 'first store of a type, however many branches elsewhere');
        $this->assertSame(LimitDecision::STORES_PER_TYPE, StoreLimitRules::newStore($l, 1, 1)->reason);
        $this->assertTrue(StoreLimitRules::newBranches($l, 2, 9)->allowed);
        $refused = StoreLimitRules::newBranches($l, 3, 3);
        $this->assertSame([false, LimitDecision::BRANCHES_PER_STORE, 3, 3], [$refused->allowed, $refused->reason, $refused->limit, $refused->used]);
        $this->assertSame(LimitDecision::BRANCHES_PER_STORE, StoreLimitRules::newBranches($l, 1, 1, 3)->reason, 'adding 3 to a store of 1');
        $this->assertSame(LimitDecision::BRANCHES_PER_STORE, StoreLimitRules::newStore($l, 0, 0, 4)->reason, 'a whole store arriving with 4 branches');
    }

    public function test_an_existing_store_changing_type_or_business_keeps_its_branches(): void
    {
        $l = StoreLimitRules::community();
        $this->assertTrue(StoreLimitRules::newStore($l, 0, 0, 7, newStore: false)->allowed, 'existing over-limit branches are not held against it');
        $this->assertSame(LimitDecision::STORES_PER_TYPE, StoreLimitRules::newStore($l, 1, 0, 7, newStore: false)->reason);
    }

    public function test_chain_lifts_the_limits_up_to_max_branches(): void
    {
        $l = StoreLimitRules::limitsFor('chain', 10);
        $this->assertSame(['edition' => 'chain', 'stores_per_type' => null, 'branches_per_store' => null, 'branches_total' => 10], $l->toArray());

        $this->assertTrue(StoreLimitRules::newStore($l, 4, 9)->allowed, 'fifth restaurant, tenth branch');
        $this->assertTrue(StoreLimitRules::newBranches($l, 8, 9)->allowed, 'ninth branch in one store');
        $refused = StoreLimitRules::newBranches($l, 9, 10);
        $this->assertSame([LimitDecision::BRANCHES_TOTAL, 10, 'chain'], [$refused->reason, $refused->limit, $refused->edition]);
        $this->assertSame(LimitDecision::BRANCHES_TOTAL, StoreLimitRules::newStore($l, 4, 10)->reason, 'a new store needs a free branch');
    }

    public function test_the_chain_count_replaces_the_community_limits(): void
    {
        $l = StoreLimitRules::limitsFor('chain', 5);
        // No per-store allowance on top of the licensed total (else every store could grow to 3 past the count).
        $this->assertSame(LimitDecision::BRANCHES_TOTAL, StoreLimitRules::newBranches($l, 1, 5)->reason);
        $this->assertSame(LimitDecision::BRANCHES_TOTAL, StoreLimitRules::newStore($l, 0, 5)->reason, 'even the first store of a type needs a free branch');
        $this->assertTrue(StoreLimitRules::newStore($l, 0, 4)->allowed);
    }

    /** @return iterable<string, array{string, int|null}> */
    public static function communityFallbacks(): iterable
    {
        yield 'chain without max_branches' => ['chain', null];
        yield 'chain with max_branches 0' => ['chain', 0];
        yield 'unknown edition' => ['ultimate', 500];
        yield 'empty edition' => ['', null];
        yield 'community' => ['community', 99];
    }

    #[DataProvider('communityFallbacks')]
    public function test_anything_unknown_is_community(string $edition, ?int $max): void
    {
        $this->assertSame(StoreLimitRules::community()->toArray(), StoreLimitRules::limitsFor($edition, $max)->toArray());
    }

    public function test_enterprise_platform_and_development_are_open_unless_max_branches_is_set(): void
    {
        foreach (['enterprise', 'platform', 'development'] as $edition) {
            $l = StoreLimitRules::limitsFor($edition, null);
            $this->assertTrue($l->unlimited(), $edition);
            $this->assertTrue(StoreLimitRules::newStore($l, 1000, 100000)->allowed);
            $this->assertTrue(StoreLimitRules::newBranches($l, 1000, 100000, 50)->allowed);
        }
        $capped = StoreLimitRules::limitsFor('enterprise', 40);
        $this->assertSame(LimitDecision::BRANCHES_TOTAL, StoreLimitRules::newBranches($capped, 10, 40)->reason);
        $this->assertTrue(StoreLimitRules::newBranches($capped, 10, 39)->allowed);
    }

    /**
     * juwri-hosted services (owner 2026-10-05): the stores and branches a service has SEEN for one installation, in the
     * order first seen — the earliest stay inside the limit, only those past it are refused.
     */
    public function test_allowance_of_seen_stores_keeps_the_first_seen_and_refuses_the_rest(): void
    {
        $seen = [
            ['ref' => '10', 'type' => 'restaurant', 'seq' => 1, 'branches' => ['b1' => 1, 'b2' => 2, 'b3' => 3, 'b4' => 4]],
            ['ref' => '11', 'type' => 'retail', 'seq' => 5, 'branches' => []],
            ['ref' => '12', 'type' => 'restaurant', 'seq' => 6, 'branches' => ['b9' => 7]],
        ];
        $c = StoreLimitRules::allowance(StoreLimitRules::community(), $seen);
        $this->assertTrue($c['10']['store']->allowed);
        $this->assertSame([true, true, true, false], array_map(fn (LimitDecision $d) => $d->allowed, array_values($c['10']['branches'])), 'Community: 3 branches per store');
        $this->assertSame(LimitDecision::BRANCHES_PER_STORE, $c['10']['branches']['b4']->reason);
        $this->assertTrue($c['11']['store']->allowed, 'another store type');
        $this->assertSame([], $c['11']['branches'], 'no branch seen = its one main branch');
        $this->assertSame(LimitDecision::STORES_PER_TYPE, $c['12']['store']->reason, 'second restaurant');
        $this->assertSame(LimitDecision::STORES_PER_TYPE, $c['12']['branches']['b9']->reason, 'a refused store refuses its branches');

        $this->assertTrue(StoreLimitRules::allowance(StoreLimitRules::limitsFor('chain', 10), $seen)['12']['store']->allowed, 'Chain: no per-type cap');
        $chain = StoreLimitRules::allowance(StoreLimitRules::limitsFor('chain', 5), $seen);
        $this->assertSame([true, true, true, true], array_map(fn (LimitDecision $d) => $d->allowed, array_values($chain['10']['branches'])), 'no per-store cap');
        // 4 (store 10) + 1 (store 11) = 5 → store 12 needs a 6th branch
        $this->assertSame(LimitDecision::BRANCHES_TOTAL, $chain['12']['store']->reason);

        $open = StoreLimitRules::allowance(StoreLimitRules::limitsFor('enterprise', null), $seen);
        foreach ($open as $decisions) {
            $this->assertTrue($decisions['store']->allowed);
            foreach ($decisions['branches'] as $d) {
                $this->assertTrue($d->allowed);
            }
        }

        // A refused branch uses none of the allowance: the next store still fits.
        $tight = StoreLimitRules::allowance(StoreLimitRules::limitsFor('chain', 2), [
            ['ref' => 'a', 'type' => 'restaurant', 'seq' => 1, 'branches' => ['x' => 1, 'y' => 2, 'z' => 3]],
            ['ref' => 'b', 'type' => 'restaurant', 'seq' => 4, 'branches' => []],
        ]);
        $this->assertSame([true, true, false], array_map(fn (LimitDecision $d) => $d->allowed, array_values($tight['a']['branches'])));
        $this->assertSame(LimitDecision::BRANCHES_TOTAL, $tight['b']['store']->reason, '2 of 2 used by store a');
        $this->assertSame([], StoreLimitRules::allowance(StoreLimitRules::community(), []));
    }

    /** One order across stores and branches: a branch seen later never pushes out a store seen earlier. */
    public function test_allowance_follows_one_first_seen_order_across_stores_and_branches(): void
    {
        $seen = [
            ['ref' => '1', 'type' => 'restaurant', 'seq' => 1, 'branches' => ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 7]],
            ['ref' => '2', 'type' => 'restaurant', 'seq' => 5, 'branches' => []],
            ['ref' => '3', 'type' => 'restaurant', 'seq' => 6, 'branches' => []],
        ];
        $chain = StoreLimitRules::allowance(StoreLimitRules::limitsFor('chain', 5), $seen);

        $this->assertTrue($chain['2']['store']->allowed, 'store 2 (5th branch) came before branch e');
        $this->assertSame(LimitDecision::BRANCHES_TOTAL, $chain['3']['store']->reason);
        $this->assertSame(LimitDecision::BRANCHES_TOTAL, $chain['1']['branches']['e']->reason, 'branch e came last');
        $this->assertTrue($chain['1']['branches']['d']->allowed);

        // Input order does not matter, only seq; numeric branch refs stay strings.
        $shuffled = StoreLimitRules::allowance(StoreLimitRules::limitsFor('chain', 2), [
            ['ref' => 'late', 'type' => 'restaurant', 'seq' => 9, 'branches' => []],
            ['ref' => 'early', 'type' => 'restaurant', 'seq' => 1, 'branches' => ['7' => 2, '8' => 3]],
        ]);
        $this->assertSame(['7', '8'], array_map('strval', array_keys($shuffled['early']['branches'])));
        $this->assertTrue($shuffled['early']['branches']['8']->allowed);
        $this->assertSame(LimitDecision::BRANCHES_TOTAL, $shuffled['late']['store']->reason);
    }
}
