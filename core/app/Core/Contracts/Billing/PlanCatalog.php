<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Contracts\Billing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Juwri\Core\Editions\Community\CommunityPlanCatalog;
use Juwri\Core\Models\User;

/**
 * P1-13 extension point: platform plans and subscriptions (owned by Billing, ee). Community has no plans: the one
 * account runs on the edition itself ({@see CommunityPlanCatalog}).
 *
 * Plan and subscription rows are returned as plain Eloquent models (or the configured free/trial plan object) so
 * Community code never names an ee class.
 */
interface PlanCatalog
{
    /** The owner's live subscription row, if any (free / Community = none). */
    public function liveSubscription(User $user): ?Model;

    /** The plan an owner is on: a plan model, or a plain object with `id`, `name` and a `settings` object. */
    public function planFor(User $user): object;

    /**
     * The plans of many owners at once (a list of stores must not query per owner); each answer is {@see planFor()}'s.
     *
     * @param  iterable<User>  $users
     * @return array<int, object> plan per user id
     */
    public function plansFor(iterable $users): array;

    /**
     * Plans, ordered by id ($activeOnly = status 1).
     *
     * @param  list<string>  $columns
     * @return Collection<int, covariant Model>
     */
    public function plans(bool $activeOnly = false, array $columns = ['*']): Collection;

    /**
     * Active, publicly listed plans from cheapest to most expensive (marketing pricing page, HPMS E14(c)).
     *
     * @return Collection<int, covariant Model>
     */
    public function publicPlans(): Collection;

    /** The owner's most recent subscription row (any status), or null. */
    public function lastSubscription(User $user): ?Model;

    /** One plan by id, or null. */
    public function find(mixed $planId): ?Model;

    /** A plan option row (admin add-on options of a plan), or null. */
    public function option(mixed $optionId): ?Model;

    /**
     * A plan's typed entitlement rows keyed by ent_key (objects with `int_value` / `bool_value`).
     *
     * @return Collection<array-key, covariant Model>
     */
    public function entitlementRows(int|string $planId): Collection;

    /**
     * Every ent_key a plan grants (bool true or int > 0) => the labels of the plans granting it
     * (free/trial config plans first as "Free plan"/"Trial plan", then plan names).
     *
     * @return array<string, list<string>>
     */
    public function grantingPlansMap(): array;

    /**
     * P1-13 13g: every plan in the admin's display order (`position`).
     *
     * @return Collection<int, covariant Model>
     */
    public function plansByPosition(): Collection;
}
