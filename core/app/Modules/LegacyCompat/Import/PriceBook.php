<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\LegacyCompat\Import;

/**
 * The source catalogue prices of ONE store, as they stand at migration time (plan §4, R2, Q3): items, their variants,
 * their add-ons (of an item or of the store's library) and the per-item price overrides of linked library add-ons,
 * read from the source (read-only) and keyed by source id. Only rows of this store are loaded, so a line that names
 * another store's item, variant or add-on finds no price here (tenant isolation) and stays unpriced.
 *
 * Raw source amounts are returned; the caller converts them with {@see LegacyMoney} in the store's currency.
 */
final class PriceBook
{
    public const ITEMS = 'menu';

    public const VARIANTS = 'menu_variants';

    public const EXTRAS = 'menu_extras';

    /** @var array<int, mixed> item id => raw price */
    private array $items = [];

    /** @var array<int, array{0: int, 1: mixed}> variant id => [item id, raw price] */
    private array $variants = [];

    /** @var array<int, mixed> extra id => raw price, add-ons of this store only (of its items, or its library) */
    private array $extras = [];

    /** @var array<int, array<int, mixed>> item id => extra id => raw per-item price override of a linked add-on */
    private array $overrides = [];

    private bool $loaded = false;

    public function __construct(private readonly SourceReader $source, private readonly int $storeId) {}

    /**
     * The price a line of $itemId (with source variant $variation, 0/NULL = none) would cost from the source menu now.
     *
     * @return array{0: mixed, 1: string}|null [raw price, basis 'variant'|'item'], or null when the item is not a row of
     *                                         this store (deleted, or another store's)
     */
    public function line(?int $itemId, ?int $variation): ?array
    {
        $this->load();
        if ($itemId === null || ! array_key_exists($itemId, $this->items)) {
            return null;
        }
        if ($variation !== null && $variation > 0 && isset($this->variants[$variation])) {
            [$owner, $price] = $this->variants[$variation];
            // A variant counts only for its own item (the source looked it up by item id AND variant id).
            if ($owner === $itemId && $price !== null && $price !== '') {
                return [$price, 'variant'];
            }
        }

        return [$this->items[$itemId], 'item'];
    }

    /**
     * What a source add-on of this store's catalogue costs on a line of $itemId: the item's price override of a linked
     * library add-on when it has one, else the add-on's own price.
     *
     * @return array{0: mixed, 1: string}|null [raw price, basis 'extra_override'|'extra'], or null when the add-on is
     *                                         not one of this store's (deleted, or another store's)
     */
    public function extra(?int $extraId, ?int $itemId = null): ?array
    {
        $this->load();
        if ($extraId === null || ! array_key_exists($extraId, $this->extras)) {
            return null;
        }
        $override = $itemId === null ? null : ($this->overrides[$itemId][$extraId] ?? null);
        if ($override !== null && $override !== '') {
            return [$override, 'extra_override'];
        }

        return [$this->extras[$extraId], 'extra'];
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;
        $column = $this->source->pick(self::ITEMS, LegacyTableMaps::STORE_KEYS);
        if ($column === null || ! in_array('price', $this->source->columns(self::ITEMS), true)) {
            return;
        }
        $this->source->chunk(self::ITEMS, function (array $rows): void {
            foreach ($rows as $row) {
                $this->items[(int) $row['id']] = $row['price'] ?? null;
            }
        }, $column, [$this->storeId]);
        if ($this->items !== []) {
            $this->variants = $this->byItem(self::VARIANTS, array_keys($this->items));
        }
        $this->loadExtras();
    }

    /**
     * The store's add-ons: those of its items and those of its library (store column, no item). One whose store column
     * names another store is not this store's, whatever item it hangs on (J29). Then the per-item overrides of the
     * store's items.
     */
    private function loadExtras(): void
    {
        $columns = $this->source->columns(self::EXTRAS);
        if (! in_array('price', $columns, true)) {
            return;
        }
        $storeColumn = in_array(ModifierMaps::STORE_COLUMN, $columns, true) ? ModifierMaps::STORE_COLUMN : null;
        $take = function (array $rows) use ($storeColumn): void {
            foreach ($rows as $row) {
                $store = $storeColumn === null ? 0 : (int) ($row[$storeColumn] ?? 0);
                $item = (int) ($row['menu_id'] ?? 0);
                $mine = $item > 0 ? array_key_exists($item, $this->items) && ($store <= 0 || $store === $this->storeId) : $store === $this->storeId;
                if ($mine) {
                    $this->extras[(int) $row['id']] = $row['price'] ?? null;
                }
            }
        };
        if ($this->items !== [] && in_array('menu_id', $columns, true)) {
            $this->source->chunk(self::EXTRAS, $take, 'menu_id', array_keys($this->items));
        }
        if ($storeColumn !== null) {
            $this->source->chunk(self::EXTRAS, $take, $storeColumn, [$this->storeId]);
        }
        $links = $this->source->columns(ModifierMaps::EXTRA_LINKS);
        if ($this->items === [] || ! in_array('price_override', $links, true) || ! in_array('menu_id', $links, true) || ! in_array('extra_id', $links, true)) {
            return;
        }
        $this->source->chunk(ModifierMaps::EXTRA_LINKS, function (array $rows): void {
            foreach ($rows as $row) {
                if (($row['price_override'] ?? null) !== null) {
                    $this->overrides[(int) $row['menu_id']][(int) $row['extra_id']] = $row['price_override'];
                }
            }
        }, 'menu_id', array_keys($this->items));
    }

    /**
     * Rows of a per-item price table (variants, extras) belonging to $itemIds.
     *
     * @param  list<int>  $itemIds
     * @return array<int, array{0: int, 1: mixed}>
     */
    private function byItem(string $table, array $itemIds): array
    {
        $columns = $this->source->columns($table);
        if (! in_array('menu_id', $columns, true) || ! in_array('price', $columns, true)) {
            return [];
        }
        $out = [];
        $this->source->chunk($table, function (array $rows) use (&$out): void {
            foreach ($rows as $row) {
                $out[(int) $row['id']] = [(int) $row['menu_id'], $row['price'] ?? null];
            }
        }, 'menu_id', $itemIds);

        return $out;
    }
}
