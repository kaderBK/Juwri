<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services\Billing;

use App\Models\StaffMember;
use App\Services\Entitlements;
use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Team\Models\StaffToken;

/**
 * P6-220: keep an owner's active branches and staff within the plan's per-store `branch_limit` / `staff_limit`.
 *
 * Over the limit (downgrade, expiry): the NEWEST active rows above the limit are switched off (active = false) and
 * stamped `plan_suspended_at` — never deleted. A switched-off branch takes no orders; a switched-off staff member
 * cannot sign in (their app tokens are revoked). The owner chooses what to keep: switch a kept one off, then a
 * suspended one on (re-activation is refused while the store is at its limit — {@see self::canActivate()}).
 * Under the limit again (upgrade): plan-suspended rows (oldest first) are switched back on up to the limit. Rows the
 * OWNER switched off are never touched.
 *
 * Runs hourly (plan-limits:sync) and when the owner opens the branches / staff page.
 */
class PlanLimitSync
{
    /** @return array{suspended:int, restored:int} */
    public function sync(User $owner): array
    {
        $result = ['suspended' => 0, 'restored' => 0];
        $limits = ['branch' => self::limit('branch', $owner), 'staff' => self::limit('staff', $owner)];
        $storeIds = Store::withoutGlobalScopes()->where('user_id', $owner->id)->pluck('id');

        foreach ($storeIds as $storeId) {
            foreach ($limits as $type => $limit) {
                $r = $this->syncStore($type, (int) $storeId, $limit);
                $result['suspended'] += $r['suspended'];
                $result['restored'] += $r['restored'];
            }
        }

        return $result;
    }

    /** The plan's per-store limit, null = unlimited. */
    public static function limit(string $type, User $owner): ?int
    {
        $limit = effective_limit($type, $owner);

        return Entitlements::isUnlimitedSentinel($limit) ? null : max(0, (int) $limit);
    }

    /** May one more row of this store be switched on (the plan's per-store limit)? */
    public static function canActivate(string $type, int $storeId, User $owner, ?int $exceptId = null): bool
    {
        $limit = self::limit($type, $owner);
        if ($limit === null) {
            return true;
        }

        return self::query($type, $storeId)->where('active', true)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->count() < $limit;
    }

    /** Rows of this owner switched off by the plan (for the owner notice). */
    public static function suspendedCount(string $type, User $owner): int
    {
        $storeIds = Store::withoutGlobalScopes()->where('user_id', $owner->id)->pluck('id');

        return self::query($type, null)->whereIn('store_id', $storeIds)->whereNotNull('plan_suspended_at')->count();
    }

    /** @return array{suspended:int, restored:int} */
    private function syncStore(string $type, int $storeId, ?int $limit): array
    {
        $active = self::query($type, $storeId)->where('active', true)->orderBy('id')->get();
        $suspended = 0;
        $restored = 0;

        if ($limit !== null && $active->count() > $limit) {
            foreach ($active->slice($limit) as $row) { // the newest above the limit
                $this->switch($row, false);
                $suspended++;
            }
        } else {
            $room = $limit === null ? PHP_INT_MAX : $limit - $active->count();
            if ($room > 0) {
                $waiting = self::query($type, $storeId)->where('active', false)->whereNotNull('plan_suspended_at')
                    ->orderBy('id')->limit(min($room, 10000))->get();
                foreach ($waiting as $row) {
                    $this->switch($row, true);
                    $restored++;
                }
            }
        }

        return ['suspended' => $suspended, 'restored' => $restored];
    }

    private function switch(Model $row, bool $on): void
    {
        $row->forceFill(['active' => $on, 'plan_suspended_at' => $on ? null : now()]);
        $row->save();
        if (! $on && $row instanceof StaffMember) {
            StaffToken::where('staff_id', $row->id)->delete(); // sign the staff app out; the account stays
        }
    }

    /** @return \Illuminate\Database\Eloquent\Builder<Branch>|\Illuminate\Database\Eloquent\Builder<StaffMember> */
    private static function query(string $type, ?int $storeId)
    {
        $q = $type === 'branch' ? Branch::withoutGlobalScopes() : StaffMember::withoutGlobalScopes();

        return $storeId === null ? $q : $q->where('store_id', $storeId);
    }
}
