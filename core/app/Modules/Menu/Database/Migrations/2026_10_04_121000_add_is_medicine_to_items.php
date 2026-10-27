<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P1-23 (AUP 3.1): in a pharmacy store an item is a medicine unless the owner marks it otherwise (cosmetics, baby
 * care). Medicines get the market's per-order quantity cap and no promotions where the market forbids them. Default
 * true = the strict reading for every existing item; ignored by every other store type.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('items', 'is_medicine')) {
            return;
        }
        Schema::table('items', function (Blueprint $table) {
            $table->boolean('is_medicine')->default(true);
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('items', 'is_medicine')) {
            Schema::table('items', function (Blueprint $table) {
                $table->dropColumn('is_medicine');
            });
        }
    }
};
