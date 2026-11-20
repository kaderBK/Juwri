<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Juwri\Modules\Ordering\Services\Orders\TenderReturnBackfill;

/**
 * money-last2 (REVIEW, Opus: money): what the stored-value tenders of an order (store credit, gift card, platform
 * wallet) became when a cancel, void or full till refund took them off the order (OrderCancellation::reverseTenders),
 * in minor units of the order currency. `tenders_returned_minor` went back to the tender it came from; `tenders_kept_minor`
 * was not given back because a refund to store credit already covered it. Every refund cap reads them (OrderTenders), so
 * a tender returned once is never paid out again. 0 on every existing order.
 */
return new class extends Migration
{
    private const COLUMNS = ['tenders_returned_minor', 'tenders_kept_minor'];

    public function up(): void
    {
        foreach (self::COLUMNS as $column) {
            if (! Schema::hasColumn('orders', $column)) {
                Schema::table('orders', function (Blueprint $table) use ($column) {
                    $table->bigInteger($column)->default(0);
                });
            }
        }

        // ML-1120 review SHOULD 1: orders whose tenders were given back before these columns existed (idempotent)
        if (class_exists(TenderReturnBackfill::class)) {
            TenderReturnBackfill::run();
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $column) {
            if (Schema::hasColumn('orders', $column)) {
                Schema::table('orders', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
