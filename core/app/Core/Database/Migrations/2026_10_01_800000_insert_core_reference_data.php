<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Juwri\Core\Database\ReferenceData;

/**
 * Core module — reference rows the platform ships with (countries, languages).
 *
 * P1-03: these are the rows the pre-juwri migrations inserted on a fresh database (feature catalogue,
 * platform defaults, countries, …), captured once by the schema generator into data/*.json.
 * '@now' marks timestamps that were "now" when the old migration ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        ReferenceData::insert(__DIR__.'/data', ['countries', 'languages']);
    }

    public function down(): void
    {
        ReferenceData::delete(__DIR__.'/data', ['languages', 'countries']);
    }
};
