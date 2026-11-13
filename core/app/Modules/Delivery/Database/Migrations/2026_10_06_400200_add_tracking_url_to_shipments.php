<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P3-08: a shipment remembers the shipping method it went out with and the carrier's tracking link (built from the
 * method's `tracking_url_template` when the owner marks it shipped), so the tracking page and the API show it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            if (! Schema::hasColumn('shipments', 'tracking_url')) {
                $table->string('tracking_url', 500)->nullable();
                $table->unsignedBigInteger('shipping_method_id')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            if (Schema::hasColumn('shipments', 'tracking_url')) {
                $table->dropColumn(['tracking_url', 'shipping_method_id']);
            }
        });
    }
};
