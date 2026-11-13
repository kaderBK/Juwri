<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Contracts;

use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\Modifier;
use Juwri\Modules\Menu\Models\ModifierGroup;

/**
 * P3-09: the grocery side of the catalogue for modules above Menu — items sold by weight (weight steps, the cap a
 * weighed line may reach), pack sizes with unit prices, and the store's "If it's out of stock" choice with its picker
 * actions (API-22, API-25, API-30). Implemented by Services\Grocery\GroceryCatalogService, bound in MenuServiceProvider.
 */
interface GroceryCatalog
{
    /** Is this item sold by weight (a weight-step group the shopper picks; the line is the step's price)? */
    public function isWeighed(Menu $item): bool;

    /**
     * The machine selling rule of an item (kit/api `SellRule` without the money formatting): kind `weighed` | `pack` |
     * `unit`, pack size + unit, the unit price in minor units per `unit_price_per`, the weight group's public id, the
     * store's weighed cap %.
     *
     * @return array{kind: string, pack_size?: float, pack_unit?: string, unit_price_minor?: int, unit_price_per?: string, weight_option_group?: string, cap_percent?: int, step_g?: int}
     */
    public function sellRule(Store $store, Menu $item): array;

    /** The store's weighed-line tolerance in percent (default 10). */
    public function capPercent(Store $store): int;

    /** The cap of a weighed estimate: estimate × (1 + cap% / 100), rounded half up to the minor unit. */
    public function capMinor(Store $store, int $estimateMinor): int;

    /**
     * The grams of the weight step a priced line chose (its `options` snapshot: [{group_id, selected: [{option}]}]),
     * or null when the item is not weighed or no step was chosen.
     *
     * @param  array<int, mixed>|null  $optionsSnapshot
     */
    public function stepGrams(Menu $item, ?array $optionsSnapshot): ?int;

    /**
     * Create or update the item's weight steps (grams, ascending), priced from its price per kg (the item price),
     * and mark the item sold by weight. Steps not listed are removed.
     *
     * @param  list<int>  $grams
     */
    public function syncWeightSteps(Menu $item, array $grams): ModifierGroup;

    /** "250 g", "1 kg", "1.5 kg": the label of a weight in grams. */
    public function stepLabel(int $grams): string;

    /** The store's substitution group (API-22) with its active options, or null when the store takes no such choice. */
    public function substitutionGroup(Store $store): ?ModifierGroup;

    /** The item's substitution group: the store's, unless the item opts out (`attributes.substitution = false`). */
    public function substitutionGroupFor(Store $store, Menu $item): ?ModifierGroup;

    /** The store's default substitution option (the setting, else the group's first active option). */
    public function defaultSubstitution(Store $store): ?Modifier;

    /** An active option of the store's substitution group by public id, or null. */
    public function substitutionOption(Store $store, string $publicId): ?Modifier;

    /**
     * API-25 / API-30: every active option of the group with its picker action (and the fallback of a call_customer
     * option, default remove_item).
     *
     * @return list<array{option_id: string, action: string, fallback?: string}>
     */
    public function pickerActions(ModifierGroup $group): array;

    /**
     * Save the store's substitution group: title + options [{id?: public id, title, sub_label?, action, fallback?}] in
     * order; options left out are switched off (orders keep naming them). Returns the group.
     *
     * @param  array<int, array<string, mixed>>  $options
     */
    public function saveSubstitutionGroup(Store $store, string $title, array $options): ModifierGroup;
}
