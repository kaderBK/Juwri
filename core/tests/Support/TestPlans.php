<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * P1-13 13i part 2b: "a store owner on a plan" for Community tests. Community sells no plans — every owner is on the
 * edition plan (`group_id` = 'free', every feature on) — so a Community test must not name a plan model. When an edition
 * package that sells plans is installed (and its test hook creates them), this returns that package's real plan row, so
 * the licensed run keeps its plan-driven behaviour; without one it returns a {@see CommunityTestPlan} stand-in.
 *
 * Use `(string) $plan->id` for `users.group_id`, exactly as before.
 */
final class TestPlans
{
    /** @param array<string, mixed> $attributes */
    public static function create(array $attributes = []): object
    {
        foreach (EditionTestHooks::all() as $hook) {
            $plan = $hook::createPlan($attributes);
            if ($plan !== null) {
                return $plan;
            }
        }

        return new CommunityTestPlan($attributes);
    }

    /**
     * Delete exactly the plans {@see create()} returned (a test without the per-test transaction cleans up what it
     * committed). A persisted edition plan is deleted through its own model's connection and table; the Community
     * stand-in has no row, so nothing is touched — a Community test never names an edition table.
     */
    public static function delete(object ...$plans): void
    {
        foreach ($plans as $plan) {
            if ($plan instanceof Model && $plan->exists) {
                $plan->getConnection()->table($plan->getTable())->where($plan->getKeyName(), $plan->getKey())->delete();
            }
        }
    }

    /**
     * The plan id for an integer `product_id` column (a platform-subscription transaction): the persisted edition
     * plan's key, or null for the Community stand-in, whose id ('free') is a `users.group_id` value, not a row id.
     */
    public static function productId(object $plan): ?int
    {
        return $plan instanceof Model && is_numeric($plan->getKey()) ? (int) $plan->getKey() : null;
    }

    /** Remove every plan the installed edition holds (no-op in Community: there are none). */
    public static function purge(): void
    {
        foreach (EditionTestHooks::all() as $hook) {
            $hook::purgePlans();
        }
    }
}
