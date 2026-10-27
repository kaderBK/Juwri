<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P4-01: what a fiscal document was issued FOR — `order:<id>` (the e-invoice of a paid order) or `refund:<id>` (the
 * credit note of one refund). UNIQUE per store, so a paid hook or a refund replayed (or racing) issues one document; a
 * correction of a rejected document leaves it null and stays on the chain as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fiscal_documents') || Schema::hasColumn('fiscal_documents', 'source_ref')) {
            return;
        }
        Schema::table('fiscal_documents', function (Blueprint $table) {
            $table->string('source_ref', 80)->nullable();
            $table->unique(['store_id', 'source_ref'], 'fd_store_source_ref_unique');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('fiscal_documents') && Schema::hasColumn('fiscal_documents', 'source_ref')) {
            Schema::table('fiscal_documents', function (Blueprint $table) {
                $table->dropUnique('fd_store_source_ref_unique');
                $table->dropColumn('source_ref');
            });
        }
    }
};
