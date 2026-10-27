<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Services\Tenancy\RowLevelSecurity;

/**
 * P1-23 (AUP 3.1(b)): the licence documents a pharmacy store uploads before it may be published — licence number,
 * issuing authority, expiry, the responsible person where the country requires one, the online-sales permit, and the
 * scanned document (private disk). A platform reviewer approves or rejects each upload; the store is published only
 * with an approved, unexpired licence in an enabled country. Tenant table (store + account), so PostgreSQL RLS is
 * switched on here — the RLS migration ran before this table existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pharmacy_licences')) {
            Schema::create('pharmacy_licences', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_id');
                $table->bigInteger('store_id')->unsigned();
                $table->string('country', 2); // ISO 3166 alpha-2 of the issuing country = the store's pharmacy market
                $table->string('licence_number', 80);
                $table->string('issuing_authority', 160);
                $table->date('expires_on');
                $table->string('responsible_person_name', 160)->nullable();
                $table->string('responsible_person_registration', 80)->nullable();
                $table->string('online_sales_permit', 160)->nullable();
                $table->string('document_path', 255);
                $table->checkedString('status', ['pending', 'approved', 'rejected'], 16)->default('pending');
                $table->string('review_note', 500)->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->dateTimeTz('reviewed_at')->nullable();
                $table->dateTimeTz('created_at')->nullable();
                $table->dateTimeTz('updated_at')->nullable();
                $table->index(['store_id'], 'pharmacy_licences_store_id_index');
                $table->index(['status'], 'pharmacy_licences_status_index');
                $table->index(['account_id', 'store_id'], 'pharmacy_licences_account_id_store_id_index');
            });
        }

        $db = DB::connection();
        if (RowLevelSecurity::supported($db) && RowLevelSecurity::enabled()) {
            foreach (array_merge(RowLevelSecurity::functionStatements(), RowLevelSecurity::tableStatements('pharmacy_licences', false)) as $sql) {
                $db->statement($sql);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacy_licences');
    }
};
