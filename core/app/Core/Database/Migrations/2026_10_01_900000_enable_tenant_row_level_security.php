<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Services\Tenancy\RowLevelSecurity;

/**
 * P1-04 slice 6 (J29-2): PostgreSQL row-level security on account_id for every tenant table, generated from
 * TenantTables (see RowLevelSecurity). Runs after every module's tables exist. A no-op on MySQL / SQLite and when
 * TENANCY_RLS=false. Not FORCEd: the migration (owner) role is not subject to it; the app role is.
 */
return new class extends Migration
{
    public function up(): void
    {
        RowLevelSecurity::sync(DB::connection());
    }

    public function down(): void
    {
        RowLevelSecurity::drop(DB::connection());
    }
};
