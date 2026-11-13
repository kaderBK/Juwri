<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * p5q int (P6-186 follow-up): a till's first invoice carries the SDK first-document PIH — base64 of the hex SHA-256 of
 * "0", 88 characters — which does not fit the 64-character column (MySQL/PostgreSQL refused the upload with a 500).
 * Widened to 128 like fiscal_documents.previous_hash.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_offline_zatca_invoices', function (Blueprint $table) {
            $table->string('previous_hash', 128)->change();
        });
    }

    public function down(): void
    {
        // never narrowed back: rows may already hold an 88-character first PIH
    }
};
