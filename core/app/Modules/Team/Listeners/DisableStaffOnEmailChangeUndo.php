<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Team\Listeners;

use App\Models\StaffMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Juwri\Modules\Auth\Events\EmailChangeUndone;
use Juwri\Modules\Team\Models\StaffToken;

/**
 * BL-036 review: a staff dashboard login signs the web guard in as the owner, so a staff member the
 * taker created or edited after the email change is a back door that survives the password lock. Every
 * staff member of this owner created since the change, or whose sign-in email, password, PIN, permissions,
 * role, active flag, store or branch changed since (security_changed_at, stamped by StaffMember) is switched off: active=false, every
 * staff-app token and passkey deleted, PIN (online and offline) revoked. Their ids go on the undo row;
 * the done page lists how many, and the owner switches the legitimate ones back on after the reset.
 * updated_at is deliberately not used: every staff sign-in bumps it through last_login_at.
 */
class DisableStaffOnEmailChangeUndo
{
    public function handle(EmailChangeUndone $event): void
    {
        $ids = StaffMember::query()->where('owner_id', (int) $event->user->id)
            ->where(function ($q) use ($event) {
                $q->where('created_at', '>=', $event->since);
                if (Schema::hasColumn('staff_members', 'security_changed_at')) {
                    $q->orWhere('security_changed_at', '>=', $event->since);
                }
            })
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($ids === []) {
            return;
        }

        $changes = ['active' => false, 'pin' => null, 'pin_hash' => null, 'updated_at' => now()];
        foreach (['pin_offline_salt', 'pin_offline_kdf'] as $col) {
            if (Schema::hasColumn('staff_members', $col)) {
                $changes[$col] = null;
            }
        }
        DB::table('staff_members')->whereIn('id', $ids)->update($changes);
        StaffToken::whereIn('staff_id', $ids)->delete();
        if (Schema::hasTable('staff_passkeys')) {
            DB::table('staff_passkeys')->whereIn('staff_id', $ids)->delete();
        }

        $event->cleared[] = 'staff';
        $event->meta['disabled_staff'] = $ids;
    }
}
