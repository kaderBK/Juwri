<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Import;

use Illuminate\Support\Str;

/**
 * Menu modifiers (DDL diff §3 "Menu modifiers", §5 #6). The source keeps item variants, option groups with their option
 * lists, add-ons (of one item, or of the store's library) and the item links of library add-ons / option groups; this
 * install keeps them as `menu_variants`, `modifier_groups` (kind variant = an option group, kind addon = the add-ons
 * of one item or of the library), `modifiers` (one row per option or add-on) and `item_modifier_group` (item links).
 *
 * Ids: variants, option groups and add-ons keep their source ids (order lines name variants and add-ons by id); the
 * add-on links keep theirs. Rows the source has no row for — each option of an option list, the add-on group of an
 * item or library, and the option-group links (whose source ids would collide with the add-on links' in the one target
 * table) — get new ids above every kept id and a side-table record under a synthetic key (resume, history guards).
 *
 * Tenancy (J29): a row belongs to the store being imported only when its item is one of that store's items (or, for a
 * library row, its own store column names the store); a row whose store column names ANOTHER store is refused and
 * reported. Links resolve only to add-ons / option groups imported for the same store. Money: prices and per-item
 * price overrides in minor units of the store currency ({@see LegacyMoney}); the platform add-on library in the
 * platform currency.
 */
final class ModifierMaps
{
    public const VARIANTS = 'menu_variants';

    public const OPTION_GROUPS = 'menu_variant_options';

    public const EXTRAS = 'menu_extras';

    public const EXTRA_LINKS = 'menu_item_extra';

    public const OPTION_GROUP_LINKS = 'menu_item_variant_option';

    public const PLATFORM_EXTRAS = 'global_menu_extras';

    public const PLATFORM_CATEGORIES = 'global_menu_categories';

    /** Source column naming the store on the later-lineage modifier tables. */
    public const STORE_COLUMN = 'restaurant_id';

    /**
     * Platform-phase loaders (platform add-on library and categories).
     *
     * @return list<TableMap>
     */
    public static function platform(): array
    {
        return [self::platformExtras(), self::platformCategories()];
    }

    /**
     * Per-store loaders, right after the items (combos and order lines name variants; order line extras name add-ons).
     * Parents first: option groups and add-ons before the links that name them.
     *
     * @return list<TableMap>
     */
    public static function catalogue(): array
    {
        return [
            self::variants(), self::optionGroups(false), self::optionGroups(true), self::extras(false), self::extras(true),
            self::extraLinks(), self::optionGroupLinks(),
        ];
    }

    /** Priced variants of an item (order lines name them by id: `order_items.variation`). */
    public static function variants(): TableMap
    {
        return new TableMap(
            source: self::VARIANTS, target: 'menu_variants', scope: TableMap::CHILD, parentColumn: 'menu_id', parentTarget: 'items',
            columns: ['options' => 'options', 'sku' => 'sku', 'stock_qty' => 'stock_qty', 'barcode' => 'barcode', 'position' => 'position'],
            bools: ['active' => 'active'],
            money: ['price_minor' => 'price'],
            currency: true,
            transform: static function (array $out): array {
                $out['position'] = (int) ($out['position'] ?? 9999);

                return $out;
            },
        );
    }

    /**
     * Option groups ("Size": Small / Large) → modifier groups of kind variant, ids kept; each option of the group's
     * list becomes a modifier row (new id) once the groups are in. `$library`: the store's library groups (no item),
     * selected by the store column; else the groups of the store's items.
     */
    public static function optionGroups(bool $library): TableMap
    {
        return new TableMap(
            source: self::OPTION_GROUPS, target: 'modifier_groups',
            scope: $library ? TableMap::STORE : TableMap::CHILD,
            storeColumns: $library ? [self::STORE_COLUMN] : [],
            parentColumn: $library ? null : 'menu_id', parentTarget: $library ? null : 'items',
            columns: [
                'title' => 'title', 'translations' => 'translations', 'position' => 'position', 'min_select' => 'min_select',
                'max_select' => 'max_select',
            ],
            bools: ['active' => 'active', 'required' => 'required', 'is_global' => 'is_global'],
            defaults: ['kind' => 'variant'],
            uses: ['options', 'option_prices', 'option_images', self::STORE_COLUMN, 'menu_id'],
            transform: static function (array $out, array $row, ImportContext $ctx) use ($library): array|string {
                if (! $library && ($why = self::foreignStore($row, $ctx)) !== null) {
                    return 'option group '.$why;
                }
                if ($library) {
                    $out['menu_id'] = null;
                }
                $out['position'] = (int) ($out['position'] ?? 9999);
                $out['min_select'] = max(0, (int) ($out['min_select'] ?? 0));
                $ctx->pendingOptionGroups[(int) $out['id']] = [
                    'options' => $row['options'] ?? null, 'option_prices' => $row['option_prices'] ?? null,
                    'option_images' => $row['option_images'] ?? null, 'translations' => $row['translations'] ?? null,
                    'menu_id' => $out['menu_id'] ?? null, 'is_global' => (bool) ($out['is_global'] ?? false),
                ];

                return $out;
            },
            rowFilter: $library ? static fn (array $row): bool => (int) ($row['menu_id'] ?? 0) <= 0 : null,
            afterLoad: static function (ImportContext $ctx, array $written): void {
                foreach ($written as $groupId) {
                    $group = $ctx->pendingOptionGroups[$groupId] ?? null;
                    unset($ctx->pendingOptionGroups[$groupId]);
                    if ($group !== null) {
                        self::writeOptions($ctx, $groupId, $group);
                    }
                }
            },
        );
    }

    /**
     * Add-ons → modifiers of kind addon, ids kept (order line extras name them), each in the add-on group of its item
     * or of the store's library (derived, created on first use as this install does). `$library`: add-ons with no item,
     * selected by the store column.
     */
    public static function extras(bool $library): TableMap
    {
        return new TableMap(
            source: self::EXTRAS, target: 'modifiers',
            scope: $library ? TableMap::STORE : TableMap::CHILD,
            storeColumns: $library ? [self::STORE_COLUMN] : [],
            parentColumn: $library ? null : 'menu_id', parentTarget: $library ? null : 'items',
            columns: [
                'title' => 'title', 'image' => 'image', 'position' => 'position', 'translations' => 'translations',
                'source_global_id' => 'source_global_id',
            ],
            bools: ['active' => 'active', 'is_global' => 'is_global'],
            money: ['price_minor' => 'price'],
            // A copy of a platform library add-on keeps that link only when the platform add-on was imported.
            refs: ['source_global_id' => self::PLATFORM_EXTRAS],
            defaults: ['kind' => 'addon'],
            currency: true,
            uses: [self::STORE_COLUMN, 'menu_id'],
            transform: static function (array $out, array $row, ImportContext $ctx) use ($library): array|string {
                if (! $library && ($why = self::foreignStore($row, $ctx)) !== null) {
                    return 'add-on '.$why;
                }
                $menuId = $library ? null : (int) $out['menu_id'];
                $out['menu_id'] = $menuId;
                $out['position'] = (int) ($out['position'] ?? 9999);
                $out['modifier_group_id'] = self::addonGroup($ctx, $menuId, (bool) ($out['is_global'] ?? false));

                return $out;
            },
            rowFilter: $library ? static fn (array $row): bool => (int) ($row['menu_id'] ?? 0) <= 0 : null,
        );
    }

    /**
     * Library add-ons linked to an item, with the per-item price override (ids kept). The add-on must be one imported
     * for the same store; the link joins the add-on's group.
     */
    public static function extraLinks(): TableMap
    {
        return new TableMap(
            source: self::EXTRA_LINKS, target: 'item_modifier_group', scope: TableMap::CHILD, parentColumn: 'menu_id', parentTarget: 'items',
            columns: ['modifier_id' => 'extra_id', 'position' => 'position'],
            bools: ['active' => 'active'],
            money: ['price_override_minor' => 'price_override'],
            requiredRefs: ['modifier_id' => 'modifiers'],
            currency: true,
            transform: static function (array $out, array $row, ImportContext $ctx): array|string {
                $group = $ctx->target->table('modifiers')->where('id', (int) $out['modifier_id'])->where('store_id', (int) $ctx->storeId)->value('modifier_group_id');
                if ($group === null) {
                    return 'add-on not imported for this store';
                }
                $out['modifier_group_id'] = (int) $group;
                $out['position'] = (int) ($out['position'] ?? 0);

                return $out;
            },
        );
    }

    /**
     * Library option groups linked to an item (min / max / required per item). New ids: the add-on links keep theirs in
     * the same target table. The group must be one imported for the same store.
     */
    public static function optionGroupLinks(): TableMap
    {
        return new TableMap(
            source: self::OPTION_GROUP_LINKS, target: 'item_modifier_group', scope: TableMap::CHILD, parentColumn: 'menu_id', parentTarget: 'items',
            columns: [
                'modifier_group_id' => 'variant_option_id', 'position' => 'position', 'min_select' => 'min_select',
                'max_select' => 'max_select',
            ],
            bools: ['required' => 'required', 'active' => 'active'],
            requiredRefs: ['modifier_group_id' => 'modifier_groups'],
            transform: static function (array $out): array {
                $out['position'] = (int) ($out['position'] ?? 0);
                $out['modifier_id'] = null;

                return $out;
            },
            freshIds: true,
        );
    }

    /** The platform's add-on library (prices in the platform currency). */
    public static function platformExtras(): TableMap
    {
        return new TableMap(
            source: self::PLATFORM_EXTRAS, target: 'global_menu_extras', scope: TableMap::PLATFORM,
            columns: ['title' => 'title', 'translations' => 'translations', 'position' => 'position'],
            dates: ['created_at' => 'created_at', 'updated_at' => 'updated_at'],
            bools: ['active' => 'active'],
            money: ['price_minor' => 'price'],
            transform: static function (array $out, array $row, ImportContext $ctx): array|string {
                if ($ctx->platformCurrency === null) {
                    return 'platform currency unknown';
                }
                $out['currency_code'] = $ctx->platformCurrency;
                $out['title'] = (string) ($out['title'] ?? '');
                $out['position'] = (int) ($out['position'] ?? 0);

                return $out;
            },
        );
    }

    /** The platform's category library (store categories copied from it keep no link: the source id is reported). */
    public static function platformCategories(): TableMap
    {
        return new TableMap(
            source: self::PLATFORM_CATEGORIES, target: 'global_menu_categories', scope: TableMap::PLATFORM,
            columns: ['parent_id' => 'parent_id', 'name' => 'name', 'translations' => 'translations', 'image' => 'image', 'position' => 'position'],
            dates: ['created_at' => 'created_at', 'updated_at' => 'updated_at'],
            bools: ['active' => 'active'],
            selfParent: 'parent_id',
            transform: static function (array $out): array {
                $out['name'] = (string) ($out['name'] ?? '');
                $out['position'] = (int) ($out['position'] ?? 0);

                return $out;
            },
        );
    }

    /**
     * Why a row reached through one of the store's items must be refused: its own store column names another store.
     *
     * @param  array<string, mixed>  $row
     */
    public static function foreignStore(array $row, ImportContext $ctx): ?string
    {
        $store = $row[self::STORE_COLUMN] ?? null;
        if ($store === null || $store === '' || (int) $store <= 0 || (int) $store === (int) $ctx->storeId) {
            return null;
        }

        return 'names another store than its item ('.self::STORE_COLUMN.')';
    }

    /**
     * The add-on group of an item ($menuId), or of the store's library ($global) / loose add-ons: derived once per store
     * (as this install creates it on first save), with a new id and a side-table record.
     */
    public static function addonGroup(ImportContext $ctx, ?int $menuId, bool $global): int
    {
        $key = $menuId !== null ? 'item:'.$menuId : 'library:'.($global ? 1 : 0);
        if (isset($ctx->addonGroups[$key])) {
            return $ctx->addonGroups[$key];
        }
        $library = $menuId === null && $global;
        $row = [
            'public_id' => (string) Str::uuid7(), 'account_id' => $ctx->accountId, 'store_id' => $ctx->storeId,
            'menu_id' => $menuId, 'kind' => 'addon', 'is_global' => $library,
            'title' => $library ? 'Add-ons library' : 'Extras', 'required' => false, 'min_select' => 0, 'position' => 9999,
            'active' => true,
        ];
        $id = RowLoader::writeDerived($ctx, 'modifier_groups', $row, self::EXTRAS, 'group:store:'.(int) $ctx->storeId.':'.$key,
            ['store' => (int) $ctx->storeId, 'group' => $key]);

        return $ctx->addonGroups[$key] = $id;
    }

    /**
     * The options of an imported option group, one modifier row each (position = list index, as this install reads
     * them back into the positional lists). Prices in minor units of the store currency (none = NULL); option label
     * translations from the group's `translations.<lang>.options`.
     *
     * @param  array<string, mixed>  $group
     */
    private static function writeOptions(ImportContext $ctx, int $groupId, array $group): void
    {
        $labels = self::listOf($group['options'] ?? null);
        $prices = self::listOf($group['option_prices'] ?? null);
        $images = self::listOf($group['option_images'] ?? null);
        $translations = self::optionTranslations($group['translations'] ?? null);
        foreach ($labels as $i => $label) {
            $raw = $prices[$i] ?? null;
            $minor = null;
            if ($raw !== null && $raw !== '') {
                $minor = is_scalar($raw) ? $ctx->minor($raw) : null;
                if ($minor === null) {
                    $ctx->report->conflict('modifiers.price_minor: option price not numeric or out of range, set NULL', self::OPTION_GROUPS.'#'.$groupId.' option '.$i);
                }
            }
            $image = $images[$i] ?? null;
            $title = is_scalar($label) ? (string) $label : (string) json_encode($label, JSON_UNESCAPED_UNICODE);
            RowLoader::writeDerived($ctx, 'modifiers', [
                'public_id' => (string) Str::uuid7(), 'account_id' => $ctx->accountId, 'store_id' => $ctx->storeId,
                'modifier_group_id' => $groupId, 'menu_id' => $group['menu_id'], 'kind' => 'variant',
                'is_global' => (bool) $group['is_global'], 'title' => $title,
                'image' => is_scalar($image) && (string) $image !== '' ? (string) $image : null,
                'price_minor' => $minor, 'currency_code' => $ctx->currency, 'position' => $i, 'active' => true,
                'translations' => isset($translations[$i]) ? (string) json_encode($translations[$i], JSON_UNESCAPED_UNICODE) : null,
            ], self::OPTION_GROUPS, $groupId.':'.$i, ['group' => $groupId, 'index' => $i, 'label' => $title, 'price' => is_scalar($raw) ? (string) $raw : null]);
        }
        $ctx->report->count('modifier_options_written', count($labels));
    }

    /**
     * A source positional list (JSON text, as this install's own option lists read it): a JSON list / object as its
     * values, any other non-empty text as a one-entry list, nothing as an empty list.
     *
     * @return list<mixed>
     */
    public static function listOf(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [$value];
        }

        return array_values((array) $value);
    }

    /**
     * Per option index: `{<lang>: {title: <label>}}` from `{<lang>: {options: [...]}}`.
     *
     * @return array<int, array<string, array{title: string}>>
     */
    private static function optionTranslations(mixed $translations): array
    {
        $decoded = is_string($translations) ? json_decode($translations, true) : null;
        if (! is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $lang => $entry) {
            $labels = is_array($entry) ? ($entry['options'] ?? null) : null;
            foreach (self::listOf(is_array($labels) ? $labels : null) as $i => $label) {
                if (is_scalar($label) && (string) $label !== '') {
                    $out[$i][(string) $lang] = ['title' => (string) $label];
                }
            }
        }

        return $out;
    }
}
