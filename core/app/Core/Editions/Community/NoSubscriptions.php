<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Editions\Community;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Juwri\Core\Contracts\Billing\Subscriptions;
use Juwri\Core\Models\User;

/**
 * P1-13 13g Community default: no platform subscriptions. Lifecycle triggers do nothing; every owner is "free", none
 * is paid or on trial; an unknown plan filter token keeps the legacy group_id predicate (as Billing does).
 */
final class NoSubscriptions implements Subscriptions
{
    public function startFromRegistration(User $user): void {}

    public function resyncFromLegacy(User $user, string $actorType = 'system', ?int $actorId = null): void {}

    public function expireLive(User $user, string $actorType): void {}

    public function forgetAccount(int $userId): void {}

    public function whereLivePaid(Builder $users, bool $paid = true): Builder
    {
        return $paid ? $users->whereRaw('1 = 0') : $users;
    }

    public function whereTrialing(Builder $users): Builder
    {
        return $users->whereRaw('1 = 0');
    }

    public function filterPlan(Builder $users, string $plan): void
    {
        if ($plan === 'free') {
            $users->where('user_type', '!=', 'admin');
        } elseif ($plan === 'trial' || is_numeric($plan)) {
            $users->whereRaw('1 = 0');
        } else {
            $users->where('group_id', $plan);
        }
    }

    public function filterPlanStatus(Builder $users, string $status): void
    {
        if (in_array($status, ['trial_active', 'trial_expired', 'expired', 'active', 'lifetime'], true)) {
            $users->whereRaw('1 = 0');
        }
    }

    public function livePaidCountsByPlan(): Collection
    {
        return collect();
    }

    public function paidEndingBetween(\DateTimeInterface $from, \DateTimeInterface $to): int
    {
        return 0;
    }

    public function latestPaused(User $user): ?Model
    {
        return null;
    }
}
