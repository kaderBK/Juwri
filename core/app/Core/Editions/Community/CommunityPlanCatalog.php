<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Editions\Community;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Juwri\Core\Contracts\Billing\PlanCatalog;
use Juwri\Core\Models\User;

/** P1-13 Community default: no platform plans; every account is on the edition plan (no limits set). */
final class CommunityPlanCatalog implements PlanCatalog
{
    public function liveSubscription(User $user): ?Model
    {
        return null;
    }

    public function planFor(User $user): object
    {
        // P1-13 13g: `status` 1 — the edition plan is always active (the storefront and the store payment pages 404 on an
        // inactive plan; without it every Community storefront failed).
        // The name is shown to the owner (account page, sidebar): product text, so it is translated (found by the
        // Arabic e2e journey on the Community build — "الخطة: Community"). The id stays the stable key.
        return (object) ['id' => 'community', 'name' => ___('Community'), 'status' => 1, 'settings' => new UnsetSettings];
    }

    public function plansFor(iterable $users): array
    {
        $out = [];
        foreach ($users as $user) {
            $out[$user->id] = $this->planFor($user);
        }

        return $out;
    }

    public function plans(bool $activeOnly = false, array $columns = ['*']): Collection
    {
        return collect();
    }

    public function publicPlans(): Collection
    {
        return collect();
    }

    public function lastSubscription(User $user): ?Model
    {
        return null;
    }

    public function find(mixed $planId): ?Model
    {
        return null;
    }

    public function option(mixed $optionId): ?Model
    {
        return null;
    }

    public function entitlementRows(int|string $planId): Collection
    {
        return collect();
    }

    public function grantingPlansMap(): array
    {
        return [];
    }

    public function plansByPosition(): Collection
    {
        return collect();
    }
}
