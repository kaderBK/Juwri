<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Services\Tenancy\RowLevelSecurity;

/**
 * P1-23: one row per legal document version a user accepted (Terms §14.13 electronic acceptance, §14.2 changes):
 * who, which document and version, when, from which IP. Tenant-scoped like the user: account_id is the user's
 * account (null until the owner's first store creates it; TenantFill::adoptUserRows fills it then).
 *
 * Contract evidence, so on purpose NO foreign key to users: removing or anonymising a user never cascades into
 * these rows (self-service deletion anonymises the user and keeps them; only the superadmin's hard erasure of the
 * whole account, store-data:delete, removes them).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_acceptances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->string('document', 32);
            $table->string('version', 16);
            $table->checkedString('source', ['signup', 'banner', 'checkout'], 16)->default('signup');
            $table->dateTimeTz('accepted_at');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->unique(['user_id', 'document', 'version'], 'legal_acceptances_user_document_version_unique');
            $table->index(['account_id'], 'legal_acceptances_account_id_index');
        });

        // The RLS policies are generated from TenantTables; this table came after the 900000 sync migration.
        RowLevelSecurity::sync(DB::connection());
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_acceptances');
    }
};
