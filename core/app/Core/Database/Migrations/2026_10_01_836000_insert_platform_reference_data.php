<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Juwri\Core\Database\ReferenceData;

/**
 * Core — reference rows an install ships with (platform_settings). P1-13 13e: moved from the Platform module (same
 * file name, so an install that ran it there does not run it again).
 *
 * P1-03: these are the rows the pre-juwri migrations inserted on a fresh database (feature catalogue,
 * platform defaults, countries, …), captured once by the schema generator into data/*.json.
 * '@now' marks timestamps that were "now" when the old migration ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        ReferenceData::insert(__DIR__.'/data', ['platform_settings']);
    }

    public function down(): void
    {
        ReferenceData::delete(__DIR__.'/data', ['platform_settings']);
    }
};
