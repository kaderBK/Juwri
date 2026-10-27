<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Contracts\Billing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Juwri\Core\Editions\Community\NoSubscriptions;
use Juwri\Core\Models\User;

/**
 * P1-13 13g extension point: the platform subscription of an owner account (Billing, ee) — its lifecycle hooks that
 * Community code triggers (sign-up, legacy plan change, account deletion) and the admin read model (paid / trial
 * filters and counts). Community has no platform subscriptions ({@see NoSubscriptions}): nobody is paid or on trial.
 */
interface Subscriptions
{
    /** Statuses of a live subscription row (Billing's Subscription::LIVE_STATUSES). */
    public const LIVE_STATUSES = ['trialing', 'active', 'past_due', 'paused'];

    /** A new owner account: start its subscription row (trial or free) as configured. */
    public function startFromRegistration(User $user): void;

    /** The legacy plan state (group_id / upgrades) changed: mirror it into the subscription row. */
    public function resyncFromLegacy(User $user, string $actorType = 'system', ?int $actorId = null): void;

    /** End the owner's live subscription now (account deletion). */
    public function expireLive(User $user, string $actorType): void;

    /** Delete the account's paid add-on and subscription rows (account purge cascade). */
    public function forgetAccount(int $userId): void;

    /**
     * Owners WITH ($paid true) or WITHOUT ($paid false) a live paid subscription.
     *
     * @param  Builder<User>  $users
     * @return Builder<User>
     */
    public function whereLivePaid(Builder $users, bool $paid = true): Builder;

    /**
     * Owners on a running trial.
     *
     * @param  Builder<User>  $users
     * @return Builder<User>
     */
    public function whereTrialing(Builder $users): Builder;

    /**
     * Admin accounts list: the "plan" filter (free / trial / a plan id / a legacy group id).
     *
     * @param  Builder<User>  $users
     */
    public function filterPlan(Builder $users, string $plan): void;

    /**
     * Admin accounts list: the "plan status" filter (trial_active / trial_expired / expired / active / lifetime).
     *
     * @param  Builder<User>  $users
     */
    public function filterPlanStatus(Builder $users, string $status): void;

    /**
     * Live paid subscriptions of owner accounts, counted per plan id.
     *
     * @return Collection<int|string, int|string>
     */
    public function livePaidCountsByPlan(): Collection;

    /** Live paid subscriptions ending between the two instants. */
    public function paidEndingBetween(\DateTimeInterface $from, \DateTimeInterface $to): int;

    /** The owner's newest paused subscription row (admin "resume"), or null. */
    public function latestPaused(User $user): ?Model;
}
