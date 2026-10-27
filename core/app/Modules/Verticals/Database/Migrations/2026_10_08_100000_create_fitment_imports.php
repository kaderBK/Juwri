<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Services\Tenancy\RowLevelSecurity;

/**
 * P3-08b (D12): the store's fitment CSV import. `fitment_imports` is one upload with its preview, its counts and the
 * first rows that failed (a bad row never aborts the file); `vehicle_fitments.vin_prefix` is the optional VIN (or its
 * first characters) a fitment row narrows to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fitment_imports', function (Blueprint $table) {
            $table->id();
            $table->publicId();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('original_name', 190);
            $table->string('path', 255)->nullable(); // the stored upload; removed when the import ends
            $table->checkedString('status', ['previewed', 'queued', 'running', 'done', 'failed'], 16)->default('previewed');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->jsonb('sample')->nullable(); // the first good rows (preview)
            $table->jsonb('errors')->nullable(); // the first failing rows: row number + reason
            $table->jsonb('meta')->nullable(); // header, ignored columns
            $table->string('message', 255)->nullable();
            $table->dateTimeTz('started_at')->nullable();
            $table->dateTimeTz('finished_at')->nullable();
            $table->dateTimeTz('created_at')->nullable();
            $table->dateTimeTz('updated_at')->nullable();
            $table->index(['store_id', 'status'], 'fitment_imports_store_id_status_index');
            $table->index(['account_id', 'store_id'], 'fitment_imports_account_id_store_id_index');
        });

        Schema::table('vehicle_fitments', function (Blueprint $table) {
            $table->string('vin_prefix', 17)->nullable()->after('engine');
            $table->index(['store_id', 'vin_prefix'], 'vehicle_fitments_store_id_vin_prefix_index');
        });

        RowLevelSecurity::sync(DB::connection());
    }

    public function down(): void
    {
        Schema::table('vehicle_fitments', function (Blueprint $table) {
            $table->dropIndex('vehicle_fitments_store_id_vin_prefix_index');
            $table->dropColumn('vin_prefix');
        });
        RowLevelSecurity::dropTable(DB::connection(), 'fitment_imports');
        Schema::dropIfExists('fitment_imports');
    }
};
