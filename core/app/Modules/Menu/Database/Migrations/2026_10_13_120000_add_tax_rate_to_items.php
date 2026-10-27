<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P6-99b (TAX-ENGINE.md): an item's own VAT. `tax_rate` (percent) NULL = the store's rate;
 * `tax_category` S standard / Z zero-rated / E exempt, NULL = S. Read only by stores on the VAT engine
 * (`tax_engine` = vat); NULL on every existing item, so nothing changes until an owner sets them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->decimal('tax_rate', 5, 2)->nullable();
            $table->string('tax_category', 1)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->dropColumn(['tax_rate', 'tax_category']);
        });
    }
};
