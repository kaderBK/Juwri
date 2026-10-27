<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BL-036 review: the role defaults gained keys that open pages staff could not reach before
 * (manager: reservations, waiter mode, dispatch, catering, food safety, inventory; kitchen: food
 * safety; waiter: reservations, waiter mode). Existing staff keep exactly the access they had:
 * each of those keys gets an explicit `false` override unless the owner already set one. New staff
 * get the new defaults; the owner switches keys on per member in the permission sheet.
 *
 * down() is a no-op on purpose: a later `false` set by the owner looks the same as ours, and
 * removing it would widen access.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> role => keys newly in its defaults */
    private const NEW_DEFAULTS = [
        'manager' => ['reservations', 'waiter', 'dispatch', 'catering', 'food_safety', 'inventory'],
        'kitchen' => ['food_safety'],
        'waiter' => ['reservations', 'waiter'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('staff_members') || ! Schema::hasColumn('staff_members', 'permissions')) {
            return;
        }
        DB::table('staff_members')->whereIn('role', array_keys(self::NEW_DEFAULTS))->orderBy('id')
            ->select(['id', 'role', 'permissions'])
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $perms = is_string($row->permissions) ? json_decode($row->permissions, true) : null;
                    $perms = is_array($perms) ? $perms : [];
                    $changed = false;
                    foreach (self::NEW_DEFAULTS[$row->role] ?? [] as $key) {
                        if (! array_key_exists($key, $perms)) {
                            $perms[$key] = false;
                            $changed = true;
                        }
                    }
                    if ($changed) {
                        DB::table('staff_members')->where('id', $row->id)->update(['permissions' => json_encode($perms)]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Intentionally empty (see the class comment).
    }
};
