<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Database\ReferenceData;

/**
 * Core — the feature catalogue the platform ships with (features; FeatureRegistry / EntitlementGates read it).
 *
 * P1-13 13g: was inserted by Billing's reference-data migration (2026_10_01_812000, which keeps addon_products); a
 * Community install has no Billing. Skipped where the catalogue already has rows (an install that ran the old
 * Billing migration). P1-03: rows captured by the schema generator into data/features.json.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('features')->exists()) {
            return;
        }
        ReferenceData::insert(__DIR__.'/data', ['features']);
    }

    public function down(): void
    {
        ReferenceData::delete(__DIR__.'/data', ['features']);
    }
};
