<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P3-09 (grocery backend) — the Menu module's part, additive only:
 *
 *  - `modifier_groups.purpose`: `weight` = the weight steps of an item sold by weight (an item-owned choice group whose
 *    option is the whole line price), `substitution` = the store's "If it's out of stock" group (API-22: store-defined
 *    options; never one of an item's priced groups). NULL = every other group.
 *  - `modifiers.weight_g`: the grams of a weight step.
 *  - `modifiers.picker_action` (API-25): the fixed picker action behind a substitution option (offer_replacement,
 *    call_customer, remove_item); `modifiers.picker_fallback` (API-30): what the picker does when a call_customer call
 *    goes unanswered (offer_replacement or remove_item; the store default is remove_item).
 *
 * Status strings with a named CHECK (checkedString; conventions §4). Nullable, no backfill: existing rows keep their
 * meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('modifier_groups', function (Blueprint $table) {
            if (! Schema::hasColumn('modifier_groups', 'purpose')) {
                $table->checkedString('purpose', ['weight', 'substitution'], 16)->nullable();
            }
        });

        Schema::table('modifiers', function (Blueprint $table) {
            if (! Schema::hasColumn('modifiers', 'weight_g')) {
                $table->unsignedInteger('weight_g')->nullable();
            }
            if (! Schema::hasColumn('modifiers', 'picker_action')) {
                $table->checkedString('picker_action', ['offer_replacement', 'call_customer', 'remove_item'], 20)->nullable();
            }
            if (! Schema::hasColumn('modifiers', 'picker_fallback')) {
                $table->checkedString('picker_fallback', ['offer_replacement', 'remove_item'], 20)->nullable();
            }
        });
    }

    public function down(): void
    {
        $checks = ['modifier_groups' => ['modifier_groups_purpose_check'], 'modifiers' => ['modifiers_picker_action_check', 'modifiers_picker_fallback_check']];
        $driver = DB::getDriverName();
        foreach ($checks as $table => $names) {
            foreach ($names as $check) {
                if ($driver === 'pgsql') {
                    DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$check}");
                } elseif (in_array($driver, ['mysql', 'mariadb'], true)
                    && DB::selectOne('SELECT 1 AS x FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?', [$table, $check]) !== null) {
                    DB::statement(\App\Support\MySqlDialect::dropCheckSql($table, $check));
                }
            }
        }
        Schema::table('modifiers', function (Blueprint $table) {
            foreach (['weight_g', 'picker_action', 'picker_fallback'] as $column) {
                if (Schema::hasColumn('modifiers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
        Schema::table('modifier_groups', function (Blueprint $table) {
            if (Schema::hasColumn('modifier_groups', 'purpose')) {
                $table->dropColumn('purpose');
            }
        });
    }
};
