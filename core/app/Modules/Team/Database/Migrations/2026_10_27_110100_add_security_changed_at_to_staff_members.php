<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BL-036 re-review: when a security-relevant field of a staff member last changed (sign-in email, password, PIN,
 * permissions, role, active, store or branch). updated_at is no use for this: every sign-in bumps it through
 * last_login_at. An email-change undo switches off staff created or security-changed since the change.
 * Existing rows stay NULL (no change recorded since this column exists).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('staff_members') || Schema::hasColumn('staff_members', 'security_changed_at')) {
            return;
        }
        Schema::table('staff_members', function (Blueprint $table) {
            $table->dateTimeTz('security_changed_at')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('staff_members') || ! Schema::hasColumn('staff_members', 'security_changed_at')) {
            return;
        }
        Schema::table('staff_members', function (Blueprint $table) {
            $table->dropColumn('security_changed_at');
        });
    }
};
