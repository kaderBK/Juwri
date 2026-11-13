<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Database\Factories;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

/**
 * SYNTHETIC source tables for the importer's menu modifier tests (no real data): option groups, item links of add-ons and
 * option groups, platform add-on library and platform categories, and the later add-on / variant columns.
 */
final class LegacyModifierFixture
{
    public function __construct(public readonly Connection $db) {}

    public function createSchema(): self
    {
        $s = $this->db->getSchemaBuilder();

        if (! $s->hasTable('menu_variant_options')) {
            $s->create('menu_variant_options', function (Blueprint $t) {
                $t->increments('id');
                $t->unsignedBigInteger('restaurant_id')->nullable();
                $t->boolean('is_global')->default(false);
                $t->integer('menu_id')->nullable();
                $t->string('title', 255)->nullable();
                $t->text('options')->nullable();
                $t->text('option_images')->nullable();
                $t->text('option_prices')->nullable();
                $t->integer('position')->default(9999);
                $t->boolean('active')->default(true);
                $t->longText('translations')->nullable();
                $t->boolean('required')->default(false);
                $t->unsignedTinyInteger('min_select')->default(0);
                $t->unsignedTinyInteger('max_select')->nullable();
            });
        }

        if (! $s->hasTable('menu_item_extra')) {
            $s->create('menu_item_extra', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('menu_id');
                $t->unsignedBigInteger('extra_id');
                $t->unsignedInteger('position')->default(0);
                $t->decimal('price_override', 10, 3)->nullable();
                $t->boolean('active')->default(true);
                $t->unique(['menu_id', 'extra_id']);
            });
        }

        if (! $s->hasTable('menu_item_variant_option')) {
            $s->create('menu_item_variant_option', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('menu_id');
                $t->unsignedBigInteger('variant_option_id');
                $t->unsignedInteger('position')->default(0);
                $t->boolean('required')->nullable();
                $t->unsignedTinyInteger('min_select')->nullable();
                $t->unsignedTinyInteger('max_select')->nullable();
                $t->boolean('active')->default(true);
                $t->unique(['menu_id', 'variant_option_id']);
            });
        }

        if (! $s->hasTable('global_menu_extras')) {
            $s->create('global_menu_extras', function (Blueprint $t) {
                $t->id();
                $t->string('title', 255);
                $t->decimal('price', 12, 3)->default(0);
                $t->longText('translations')->nullable();
                $t->integer('position')->default(0);
                $t->boolean('active')->default(true);
                $t->timestamp('created_at')->nullable();
                $t->timestamp('updated_at')->nullable();
            });
        }

        if (! $s->hasTable('global_menu_categories')) {
            $s->create('global_menu_categories', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('parent_id')->nullable();
                $t->string('name', 255);
                $t->longText('translations')->nullable();
                $t->string('image', 255)->nullable();
                $t->integer('position')->default(0);
                $t->boolean('active')->default(true);
                $t->timestamp('created_at')->nullable();
                $t->timestamp('updated_at')->nullable();
            });
        }

        return $this;
    }

    /**
     * The later source versions' add-on and variant columns: the tables are built by LegacyHistoryFixture, which has
     * none of these columns, so they are added one by one (sqlite adds one column per ALTER).
     */
    public function addLaterColumns(): self
    {
        $s = $this->db->getSchemaBuilder();

        if (! $s->hasColumn('menu_extras', 'restaurant_id')) {
            $s->table('menu_extras', function (Blueprint $t) {
                $t->unsignedBigInteger('restaurant_id')->nullable();
            });
        }

        if (! $s->hasColumn('menu_extras', 'is_global')) {
            $s->table('menu_extras', function (Blueprint $t) {
                $t->boolean('is_global')->default(false);
            });
        }

        if (! $s->hasColumn('menu_extras', 'image')) {
            $s->table('menu_extras', function (Blueprint $t) {
                $t->string('image', 255)->nullable();
            });
        }

        if (! $s->hasColumn('menu_extras', 'source_global_id')) {
            $s->table('menu_extras', function (Blueprint $t) {
                $t->unsignedBigInteger('source_global_id')->nullable();
            });
        }

        if (! $s->hasColumn('menu_variants', 'sku')) {
            $s->table('menu_variants', function (Blueprint $t) {
                $t->string('sku', 64)->nullable();
            });
        }

        if (! $s->hasColumn('menu_variants', 'stock_qty')) {
            $s->table('menu_variants', function (Blueprint $t) {
                $t->integer('stock_qty')->nullable();
            });
        }

        if (! $s->hasColumn('menu_variants', 'barcode')) {
            $s->table('menu_variants', function (Blueprint $t) {
                $t->string('barcode', 64)->nullable();
            });
        }

        return $this;
    }

    /**
     * An option group row: a group of item $menuId, or of the store library when $menuId is null.
     *
     * @param  list<string>  $options
     * @param  list<float|int|string|null>|null  $prices
     * @param  array<string, mixed>  $attrs
     */
    public function optionGroup(int $id, ?int $storeId, ?int $menuId, string $title, array $options, ?array $prices = null, array $attrs = []): int
    {
        return $this->insert('menu_variant_options', $attrs + [
            'id' => $id,
            'restaurant_id' => $storeId,
            'menu_id' => $menuId,
            'title' => $title,
            'options' => json_encode(array_values($options)),
            'option_prices' => $prices === null ? null : json_encode(array_values($prices)),
            'is_global' => $menuId === null,
        ]);
    }

    /** @param array<string, mixed> $attrs */
    public function itemExtraLink(int $id, int $menuId, int $extraId, float|string|null $priceOverride = null, array $attrs = []): int
    {
        return $this->insert('menu_item_extra', $attrs + [
            'id' => $id,
            'menu_id' => $menuId,
            'extra_id' => $extraId,
            'price_override' => $priceOverride,
        ]);
    }

    /** @param array<string, mixed> $attrs */
    public function itemOptionGroupLink(int $id, int $menuId, int $optionGroupId, array $attrs = []): int
    {
        return $this->insert('menu_item_variant_option', $attrs + [
            'id' => $id,
            'menu_id' => $menuId,
            'variant_option_id' => $optionGroupId,
        ]);
    }

    /** @param array<string, mixed> $attrs */
    public function platformExtra(int $id, string $title, float|string $price, array $attrs = []): int
    {
        return $this->insert('global_menu_extras', $attrs + [
            'id' => $id,
            'title' => $title,
            'price' => $price,
            'created_at' => '2025-03-01 10:00:00',
            'updated_at' => '2025-03-01 10:00:00',
        ]);
    }

    /** @param array<string, mixed> $attrs */
    public function platformCategory(int $id, string $name, ?int $parentId = null, array $attrs = []): int
    {
        return $this->insert('global_menu_categories', $attrs + [
            'id' => $id,
            'parent_id' => $parentId,
            'name' => $name,
        ]);
    }

    /** @param array<string, mixed> $row */
    private function insert(string $table, array $row): int
    {
        $this->db->table($table)->insert($row);

        return (int) $row['id'];
    }
}
