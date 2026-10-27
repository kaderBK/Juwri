<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Services\Tenancy\RowLevelSecurity;

/**
 * P3-08 (retail backend) — the Menu module's part, additive only (owner 10-01: `menu_variants` stays the item's
 * variations; extend, don't replace):
 *
 *  - `menu_variants`: the colour × size matrix keeps its row per combination (`options` = {group id: option index}
 *    over the item's own option groups); each cell gains a compare-at price (`compare_at_minor`, minor units of
 *    `currency_code`), its own low-stock threshold (API-6; NULL = the item's), an image and a GTIN.
 *  - `modifier_groups.display`: how a storefront draws a choice group (`swatch`, `size`, `chips`; NULL = radio /
 *    checkbox by its max), `modifiers.swatch`: a colour option's swatch (#RRGGBB).
 *  - `items.size_guide_id` → `size_guides`: the size guide content type (a measurement table per store).
 *  - `wishlist_items`: a shopper's saved items — a signed-in customer's (`customer_id`), or a guest's (`guest_key` =
 *    sha256 of the browser's wishlist token), merged into the customer's list at sign-in.
 *  - `stock_alerts`: back-in-stock requests (N5) — sent once, only with the shopper's marketing opt-in.
 *
 * Every new table is a store tenant table: account_id + store_id, store-leading keys, PostgreSQL RLS switched on here
 * (the RLS migration ran before these tables existed).
 */
return new class extends Migration
{
    private const NEW_TABLES = ['size_guides', 'wishlist_items', 'stock_alerts'];

    public function up(): void
    {
        Schema::table('menu_variants', function (Blueprint $table) {
            if (! Schema::hasColumn('menu_variants', 'compare_at_minor')) {
                $table->bigInteger('compare_at_minor')->nullable(); // P3-08: struck-through "was" price, minor units
            }
            if (! Schema::hasColumn('menu_variants', 'low_stock_threshold')) {
                $table->smallInteger('low_stock_threshold')->unsigned()->nullable(); // API-6; NULL = the item's
            }
            if (! Schema::hasColumn('menu_variants', 'image')) {
                $table->string('image', 255)->nullable();
            }
            if (! Schema::hasColumn('menu_variants', 'gtin')) {
                $table->string('gtin', 14)->nullable();
            }
        });

        Schema::table('modifier_groups', function (Blueprint $table) {
            if (! Schema::hasColumn('modifier_groups', 'display')) {
                $table->string('display', 16)->nullable(); // swatch | size | chips; NULL = radio / checkbox
            }
        });

        Schema::table('modifiers', function (Blueprint $table) {
            if (! Schema::hasColumn('modifiers', 'swatch')) {
                $table->string('swatch', 7)->nullable(); // #RRGGBB
            }
        });

        if (! Schema::hasTable('size_guides')) {
            Schema::create('size_guides', function (Blueprint $table) {
                $table->id();
                $table->publicId();
                $table->unsignedBigInteger('account_id');
                $table->bigInteger('store_id')->unsigned();
                $table->string('title', 120);
                $table->jsonb('translations')->nullable();
                $table->checkedString('unit', ['cm', 'in'])->default('cm');
                $table->json('columns'); // header labels: ["Size", "Chest", "Waist"]
                $table->json('rows'); // [["S", "88–92", "72–76"], …]
                $table->text('note')->nullable();
                $table->boolean('active')->default(true);
                $table->integer('position')->default(0);
                $table->dateTimeTz('created_at')->nullable();
                $table->dateTimeTz('updated_at')->nullable();
                $table->index(['store_id', 'active'], 'size_guides_store_active_index');
                $table->index(['account_id', 'store_id'], 'size_guides_account_id_store_id_index');
                $table->foreign(['store_id'], 'size_guides_store_id_foreign')->references(['id'])->on('stores')->onDelete('cascade');
            });
        }

        Schema::table('items', function (Blueprint $table) {
            if (! Schema::hasColumn('items', 'size_guide_id')) {
                $table->unsignedBigInteger('size_guide_id')->nullable();
                $table->index(['size_guide_id'], 'items_size_guide_id_index');
            }
        });

        if (! Schema::hasTable('wishlist_items')) {
            Schema::create('wishlist_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('account_id');
                $table->bigInteger('store_id')->unsigned();
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->char('guest_key', 64)->nullable(); // sha256 of the guest's wishlist token
                $table->unsignedBigInteger('menu_id');
                $table->unsignedBigInteger('variant_id')->nullable();
                $table->dateTimeTz('created_at')->nullable();
                $table->unique(['store_id', 'customer_id', 'menu_id'], 'wishlist_items_store_customer_menu_unique');
                $table->unique(['store_id', 'guest_key', 'menu_id'], 'wishlist_items_store_guest_menu_unique');
                $table->index(['account_id', 'store_id'], 'wishlist_items_account_id_store_id_index');
                $table->index(['menu_id'], 'wishlist_items_menu_id_index');
                $table->foreign(['store_id'], 'wishlist_items_store_id_foreign')->references(['id'])->on('stores')->onDelete('cascade');
                $table->foreign(['menu_id'], 'wishlist_items_menu_id_foreign')->references(['id'])->on('items')->onDelete('cascade');
            });
        }

        if (! Schema::hasTable('stock_alerts')) {
            Schema::create('stock_alerts', function (Blueprint $table) {
                $table->id();
                $table->publicId();
                $table->unsignedBigInteger('account_id');
                $table->bigInteger('store_id')->unsigned();
                $table->unsignedBigInteger('menu_id');
                $table->unsignedBigInteger('variant_id')->nullable();
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->checkedString('channel', ['email', 'whatsapp', 'sms']);
                $table->text('contact'); // encrypted (email address or phone number)
                $table->char('contact_hash', 64); // sha256 of the normalised contact, for "one request per contact"
                $table->string('target', 40); // "m<item id>:v<variant id or 0>"
                $table->string('locale', 8)->nullable();
                $table->checkedString('status', ['waiting', 'sent', 'cancelled', 'failed'])->default('waiting');
                $table->dateTimeTz('opted_in_at'); // N5: the shopper's marketing opt-in (required)
                $table->dateTimeTz('notified_at')->nullable();
                $table->dateTimeTz('created_at')->nullable();
                $table->dateTimeTz('updated_at')->nullable();
                $table->unique(['store_id', 'target', 'contact_hash'], 'stock_alerts_store_target_contact_unique');
                $table->index(['store_id', 'menu_id', 'status'], 'stock_alerts_store_menu_status_index');
                $table->index(['account_id', 'store_id'], 'stock_alerts_account_id_store_id_index');
                $table->foreign(['store_id'], 'stock_alerts_store_id_foreign')->references(['id'])->on('stores')->onDelete('cascade');
                $table->foreign(['menu_id'], 'stock_alerts_menu_id_foreign')->references(['id'])->on('items')->onDelete('cascade');
            });
        }

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
        foreach (array_reverse(self::NEW_TABLES) as $name) {
            Schema::dropIfExists($name);
        }
        Schema::table('items', function (Blueprint $table) {
            if (Schema::hasColumn('items', 'size_guide_id')) {
                $table->dropIndex('items_size_guide_id_index');
                $table->dropColumn('size_guide_id');
            }
        });
        Schema::table('modifiers', function (Blueprint $table) {
            if (Schema::hasColumn('modifiers', 'swatch')) {
                $table->dropColumn('swatch');
            }
        });
        Schema::table('modifier_groups', function (Blueprint $table) {
            if (Schema::hasColumn('modifier_groups', 'display')) {
                $table->dropColumn('display');
            }
        });
        Schema::table('menu_variants', function (Blueprint $table) {
            foreach (['gtin', 'image', 'low_stock_threshold', 'compare_at_minor'] as $column) {
                if (Schema::hasColumn('menu_variants', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
