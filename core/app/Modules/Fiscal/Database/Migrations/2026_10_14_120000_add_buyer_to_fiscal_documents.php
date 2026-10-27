<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P6-176: the buyer of a standard (B2B) ZATCA tax invoice — name, VAT number and national address as the merchant
 * entered them — kept on the document it was issued on (a later credit note of that invoice carries the same buyer).
 * Null on simplified (B2C) documents.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fiscal_documents') || Schema::hasColumn('fiscal_documents', 'buyer')) {
            return;
        }
        Schema::table('fiscal_documents', function (Blueprint $table) {
            $table->json('buyer')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('fiscal_documents') && Schema::hasColumn('fiscal_documents', 'buyer')) {
            Schema::table('fiscal_documents', function (Blueprint $table) {
                $table->dropColumn('buyer');
            });
        }
    }
};
