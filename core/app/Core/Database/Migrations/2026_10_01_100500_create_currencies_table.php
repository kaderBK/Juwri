<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Database\ReferenceData;
use Juwri\Core\Money\Currencies;

/**
 * P1-05 (J25-4): ISO 4217 currencies with their minor-unit exponent (JOD/KWD/BHD/OMR 3, JPY 0, …).
 * Money is stored as `*_minor` integers; the exponent says how many minor units make one major unit.
 * Rows come from brick/money's ISO data (current + historical, data/currencies.json); a code that is not
 * listed resolves to exponent 2 ({@see Currencies}).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->char('code', 3)->unique('currencies_code_unique');
            $table->unsignedSmallInteger('numeric_code')->nullable();
            $table->string('name', 100);
            $table->unsignedTinyInteger('exponent')->default(2);
            $table->boolean('historical')->default(false);
        });

        ReferenceData::insert(__DIR__.'/data', ['currencies']);
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
