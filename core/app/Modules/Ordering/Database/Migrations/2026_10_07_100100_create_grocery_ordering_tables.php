<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Services\Tenancy\RowLevelSecurity;

/**
 * P3-09 (grocery backend) — the Ordering module's part:
 *
 *  - `delivery_slot_rules`: the store's delivery / collection time slots (one row for the store, `branch_id` NULL, and
 *    optionally one per branch that overrides it): hourly slots inside the `windows` (HH:MM pairs, store time), at most
 *    `capacity_per_hour` orders per branch per hourly slot (0 = no limit), bookable from `lead_minutes` ahead up to
 *    `days_ahead` days, an express option (`express_fee_minor` NULL = off, ETA minutes) and a delivery radius in km
 *    from the branch (or the rule's base point).
 *  - `slot_holds` (API-3, API-11): a 10-minute hold of one slot for a shopper (`token_hash` = sha256 of the hold token
 *    returned once), bound to a hosted order until its payment window ends (API-10), used by the order that takes it.
 *  - `slot_seats`: the capacity guard. A held or ordered slot owns one seat numbered 1..capacity; UNIQUE(store_id,
 *    branch_key, slot_at, seat_no) lets only one of two concurrent takers commit a seat, on SQLite, MySQL and
 *    PostgreSQL alike. A hold's seat expires with it (`expires_at`); an order's seat has none and is freed when the
 *    order is cancelled.
 *  - `orders`: the slot (window + hold), express, the order-level substitution choice (API-22), the card hold of a
 *    weighed basket (authorised amount, its state, the captured amount) and the picking state.
 *  - `order_items`: the weighed line (grams per unit ordered, its cap), the line's substitution choice and picker action
 *    (API-25, API-30), and what picking did (actual grams, final amount, replacement, removal, refund — API-31).
 *
 * Store tenant tables: account_id + store_id, store-leading keys, PostgreSQL RLS switched on here.
 */
return new class extends Migration
{
    private const NEW_TABLES = ['delivery_slot_rules', 'slot_holds', 'slot_seats'];

    public function up(): void
    {
        if (! Schema::hasTable('delivery_slot_rules')) {
            Schema::create('delivery_slot_rules', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_id');
                $table->bigInteger('store_id')->unsigned();
                $table->unsignedBigInteger('branch_id')->nullable(); // NULL = the store's rule
                $table->boolean('active')->default(true);
                $table->json('windows'); // [["09:00","13:00"],["15:00","21:00"]] store time; split into hourly slots
                $table->unsignedSmallInteger('capacity_per_hour')->default(0); // 0 = no limit
                $table->unsignedSmallInteger('lead_minutes')->default(60);
                $table->unsignedTinyInteger('days_ahead')->default(3);
                $table->bigInteger('express_fee_minor')->nullable(); // NULL = no express option
                $table->unsignedSmallInteger('express_eta_from')->nullable();
                $table->unsignedSmallInteger('express_eta_to')->nullable();
                $table->decimal('radius_km', 6, 2)->nullable(); // NULL = no radius check
                $table->decimal('base_lat', 10, 7)->nullable(); // used when the branch has no coordinates
                $table->decimal('base_lng', 10, 7)->nullable();
                $table->char('currency_code', 3)->nullable();
                $table->dateTimeTz('created_at')->nullable();
                $table->dateTimeTz('updated_at')->nullable();
                $table->index(['store_id', 'branch_id'], 'delivery_slot_rules_store_branch_index');
                $table->index(['account_id', 'store_id'], 'delivery_slot_rules_account_id_store_id_index');
                $table->foreign(['store_id'], 'delivery_slot_rules_store_id_foreign')->references(['id'])->on('stores')->onDelete('cascade');
            });
        }

        if (! Schema::hasTable('slot_holds')) {
            Schema::create('slot_holds', function (Blueprint $table) {
                $table->id();
                $table->publicId();
                $table->unsignedBigInteger('account_id');
                $table->bigInteger('store_id')->unsigned();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->string('order_type', 32); // core order type the slot was listed for (delivery, pickup_slot, …)
                $table->string('slot_id', 64); // the public slot id that was held
                $table->dateTimeTz('slot_starts_at');
                $table->dateTimeTz('slot_ends_at');
                $table->dateTimeTz('expires_at');
                $table->char('token_hash', 64);
                $table->text('token_encrypted')->nullable(); // the hold token, encrypted: an idempotent replay answers it again
                $table->char('request_hash', 64)->nullable(); // the hold request (slot), for idempotency conflicts
                $table->char('holder_key', 64)->nullable(); // sha256(storefront token id + client IP)
                $table->string('idempotency_key', 64)->nullable();
                $table->checkedString('status', ['active', 'bound', 'used', 'released', 'expired'], 10)->default('active');
                $table->unsignedBigInteger('order_id')->nullable();
                $table->dateTimeTz('created_at')->nullable();
                $table->dateTimeTz('updated_at')->nullable();
                $table->index(['store_id', 'status', 'expires_at'], 'slot_holds_store_status_expires_index');
                $table->index(['holder_key', 'status'], 'slot_holds_holder_status_index');
                $table->index(['store_id', 'idempotency_key'], 'slot_holds_store_idempotency_index');
                $table->index(['order_id'], 'slot_holds_order_id_index');
                $table->index(['account_id', 'store_id'], 'slot_holds_account_id_store_id_index');
                $table->foreign(['store_id'], 'slot_holds_store_id_foreign')->references(['id'])->on('stores')->onDelete('cascade');
            });
        }

        if (! Schema::hasTable('slot_seats')) {
            Schema::create('slot_seats', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_id');
                $table->bigInteger('store_id')->unsigned();
                $table->unsignedBigInteger('branch_key')->default(0); // the branch id, 0 for a store without branches
                $table->dateTimeTz('slot_at'); // UTC start of the hourly slot
                $table->unsignedSmallInteger('seat_no');
                $table->unsignedBigInteger('hold_id')->nullable();
                $table->unsignedBigInteger('order_id')->nullable();
                $table->dateTimeTz('expires_at')->nullable(); // a hold's seat; NULL once an order owns it
                $table->unique(['store_id', 'branch_key', 'slot_at', 'seat_no'], 'slot_seats_slot_seat_unique');
                $table->index(['hold_id'], 'slot_seats_hold_id_index');
                $table->index(['order_id'], 'slot_seats_order_id_index');
                $table->index(['store_id', 'expires_at'], 'slot_seats_store_expires_index');
                $table->index(['account_id', 'store_id'], 'slot_seats_account_id_store_id_index');
                $table->foreign(['store_id'], 'slot_seats_store_id_foreign')->references(['id'])->on('stores')->onDelete('cascade');
            });
        }

        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'slot_hold_id')) {
                $table->unsignedBigInteger('slot_hold_id')->nullable();
                $table->dateTimeTz('slot_starts_at')->nullable();
                $table->dateTimeTz('slot_ends_at')->nullable();
                $table->boolean('express')->default(false);
                $table->unsignedBigInteger('substitution_option_id')->nullable();
                $table->bigInteger('hold_minor')->nullable(); // the card hold authorised for a weighed basket
                $table->checkedString('hold_status', ['authorised', 'captured', 'voided'], 12)->nullable();
                $table->bigInteger('captured_minor')->nullable();
                $table->dateTimeTz('captured_at')->nullable();
                $table->checkedString('picking_status', ['picking', 'picked'], 10)->nullable();
                $table->dateTimeTz('picked_at')->nullable();
                $table->index(['store_id', 'slot_starts_at'], 'orders_store_slot_starts_index');
            }
        });

        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'weight_g')) {
                $table->unsignedInteger('weight_g')->nullable(); // a weighed line: grams per unit ordered
                $table->bigInteger('cap_minor')->nullable(); // the most this weighed line can cost
                $table->unsignedBigInteger('substitution_option_id')->nullable();
                $table->checkedString('picker_action', ['offer_replacement', 'call_customer', 'remove_item'], 20)->nullable();
                $table->checkedString('picker_fallback', ['offer_replacement', 'remove_item'], 20)->nullable();
                $table->checkedString('pick_status', ['picked', 'proposed', 'replaced', 'removed'], 10)->nullable();
                $table->unsignedInteger('actual_g')->nullable();
                $table->bigInteger('final_minor')->nullable(); // set by picking: replaces the computed line total
                $table->unsignedBigInteger('replacement_item_id')->nullable();
                $table->string('replacement_name', 255)->nullable();
                $table->bigInteger('replacement_minor')->nullable();
                $table->checkedString('replacement_answer', ['kept', 'refused'], 8)->nullable();
                $table->bigInteger('refunded_minor')->nullable(); // API-31: a line removed after capture
                $table->dateTimeTz('picked_at')->nullable();
            }
        });

        $db = DB::connection();
        if (RowLevelSecurity::supported($db) && RowLevelSecurity::enabled()) {
            $statements = RowLevelSecurity::functionStatements();
            foreach (self::NEW_TABLES as $name) {
                $statements = array_merge($statements, RowLevelSecurity::tableStatements($name, false));
            }
            foreach ($statements as $sql) {
                $db->statement($sql);
            }
        }
    }

    public function down(): void
    {
        $checks = [
            'orders' => ['orders_hold_status_check', 'orders_picking_status_check'],
            'order_items' => ['order_items_picker_action_check', 'order_items_picker_fallback_check', 'order_items_pick_status_check', 'order_items_replacement_answer_check'],
        ];
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
        Schema::table('order_items', function (Blueprint $table) {
            if (Schema::hasColumn('order_items', 'weight_g')) {
                $table->dropColumn(['weight_g', 'cap_minor', 'substitution_option_id', 'picker_action', 'picker_fallback', 'pick_status', 'actual_g',
                    'final_minor', 'replacement_item_id', 'replacement_name', 'replacement_minor', 'replacement_answer', 'refunded_minor', 'picked_at']);
            }
        });
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'slot_hold_id')) {
                $table->dropIndex('orders_store_slot_starts_index');
                $table->dropColumn(['slot_hold_id', 'slot_starts_at', 'slot_ends_at', 'express', 'substitution_option_id', 'hold_minor', 'hold_status',
                    'captured_minor', 'captured_at', 'picking_status', 'picked_at']);
            }
        });
        foreach (array_reverse(self::NEW_TABLES) as $name) {
            RowLevelSecurity::dropTable(DB::connection(), $name);
            Schema::dropIfExists($name);
        }
    }
};
