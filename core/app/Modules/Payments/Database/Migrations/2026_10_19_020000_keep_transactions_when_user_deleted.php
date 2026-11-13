<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P6-246 follow-up: `transaction.user_id` cascaded on user delete, so removing an account erased its paid payments
 * (receipts, tax, dispute matching, fiscal retention). The FK now sets NULL and the row keeps an anonymised
 * reference to the former account (sha256 of the user id + app key, stamped before any delete).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
        Schema::table('transaction', function (Blueprint $table) {
            if (! Schema::hasColumn('transaction', 'former_user_ref')) {
                $table->string('former_user_ref', 64)->nullable();
            }
            $table->foreign(['user_id'], 'transaction_user_id_foreign')->references(['id'])->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transaction', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });
        Schema::table('transaction', function (Blueprint $table) {
            $table->foreign(['user_id'], 'transaction_user_id_foreign')->references(['id'])->on('users')->onDelete('cascade');
            if (Schema::hasColumn('transaction', 'former_user_ref')) {
                $table->dropColumn('former_user_ref');
            }
        });
    }
};
