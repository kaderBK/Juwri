<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Services\Tenancy\RowLevelSecurity;

/**
 * P4-22 switching tools: one row per import of a store — the parsed rows (ENCRYPTED: customers' names, phones and
 * e-mails are personal data; cleared once committed), the dry-run plan shown to the owner, and what the commit did.
 * status: previewed → committed (once; a second commit is refused) | discarded. Previews older than a day are deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('switch_imports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('account_id');
            $table->unsignedBigInteger('store_id');
            $table->unsignedBigInteger('user_id');
            $table->checkedString('dataset', ['menu', 'customers', 'loyalty'], 16);
            $table->string('source', 40);
            $table->checkedString('mode', ['add', 'update'], 8)->default('add');
            $table->string('file_hash', 64)->nullable();
            $table->checkedString('status', ['previewed', 'committed', 'discarded'], 16)->default('previewed');
            $table->longText('rows')->nullable();
            $table->json('plan')->nullable();
            $table->json('summary')->nullable();
            $table->dateTimeTz('committed_at')->nullable();
            $table->dateTimeTz('created_at')->nullable();
            $table->dateTimeTz('updated_at')->nullable();
            $table->index(['store_id', 'status'], 'switch_imports_store_id_status_index');
            $table->index(['account_id', 'store_id'], 'switch_imports_account_id_store_id_index');
        });

        RowLevelSecurity::sync(DB::connection());
    }

    public function down(): void
    {
        RowLevelSecurity::dropTable(DB::connection(), 'switch_imports');
        Schema::dropIfExists('switch_imports');
    }
};
