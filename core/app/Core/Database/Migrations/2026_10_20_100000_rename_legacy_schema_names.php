<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * J52: legacy-name rename of the remaining schema names (nothing live yet, no aliases). Re-run safe: every step checks
 * the old name exists and the new one does not.
 *  - store_transfers.target_post_id        -> target_store_id
 *  - blog_topics.reseller_post_id          -> reseller_blog_post_id
 *  - index customers_restaurant_oauth_unique   -> customers_store_oauth_unique
 *  - index items_restaurant_external_sku_index -> items_store_external_sku_index
 *  - platform_settings keys quickad_debug -> juwri_debug, restaurant_text_editor -> store_text_editor,
 *    reseller_posts_max -> reseller_blog_posts_max
 * The Enterprise reseller table rename (reseller_posts -> reseller_blog_posts) lives in the Reseller module.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->renameColumn('store_transfers', 'target_post_id', 'target_store_id');
        $this->renameColumn('blog_topics', 'reseller_post_id', 'reseller_blog_post_id');
        $this->renameIndex('customers', 'customers_restaurant_oauth_unique', 'customers_store_oauth_unique');
        $this->renameIndex('items', 'items_restaurant_external_sku_index', 'items_store_external_sku_index');

        if (Schema::hasTable('platform_settings')) {
            foreach (['quickad_debug' => 'juwri_debug', 'restaurant_text_editor' => 'store_text_editor', 'reseller_posts_max' => 'reseller_blog_posts_max'] as $old => $new) {
                if (DB::table('platform_settings')->where('name', $new)->exists()) {
                    DB::table('platform_settings')->where('name', $old)->delete();
                } else {
                    DB::table('platform_settings')->where('name', $old)->update(['name' => $new]);
                }
            }
        }
    }

    public function down(): void
    {
        // forward-only (J52): the old names are retired.
    }

    private function renameColumn(string $table, string $old, string $new): void
    {
        if (Schema::hasTable($table) && Schema::hasColumn($table, $old) && ! Schema::hasColumn($table, $new)) {
            Schema::table($table, fn (Blueprint $t) => $t->renameColumn($old, $new));
        }
    }

    private function renameIndex(string $table, string $old, string $new): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        $names = array_column(Schema::getIndexes($table), 'name');
        if (in_array($old, $names, true) && ! in_array($new, $names, true)) {
            Schema::table($table, fn (Blueprint $t) => $t->renameIndex($old, $new));
        }
    }
};
