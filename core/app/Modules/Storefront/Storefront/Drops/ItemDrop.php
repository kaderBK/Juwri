<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront\Drops;

use Illuminate\Support\Collection;
use Juwri\Modules\Menu\Models\Menu;
use Keepsuit\Liquid\Attributes\Cache;
use Keepsuit\Liquid\Drop;

/**
 * P2-2: a menu item. Wraps a Menu model but exposes only presentation fields
 * and its child collections as Drops — never the Eloquent relation graph.
 */
class ItemDrop extends Drop
{
    public function __construct(protected Menu $menu) {}

    /** Not exposed — a loaded relation as a collection, or empty if absent. */
    protected function rel(string $name): Collection
    {
        return $this->menu->relationLoaded($name)
            ? collect($this->menu->getRelation($name))
            : collect();
    }

    public function id(): int
    {
        return (int) $this->menu->id;
    }

    /** Translated name (Menu::getNameAttribute resolves the active language). */
    public function name(): ?string
    {
        return $this->menu->name;
    }

    public function description(): ?string
    {
        return $this->menu->description;
    }

    public function price(): float
    {
        return (float) $this->menu->price;
    }

    /** Raw stored filename — theme builds the URL via the `media_url` filter. */
    public function image(): ?string
    {
        return $this->menu->image;
    }

    // Dietary / status flags
    public function is_vegan(): bool
    {
        return (bool) $this->menu->is_vegan;
    }

    public function is_halal(): bool
    {
        return (bool) $this->menu->is_halal;
    }

    public function is_gluten_free(): bool
    {
        return (bool) $this->menu->is_gluten_free;
    }

    public function is_popular(): bool
    {
        return (bool) $this->menu->is_popular;
    }

    public function is_daily_special(): bool
    {
        return (bool) $this->menu->is_daily_special;
    }

    public function is_sold_out(): bool
    {
        return (bool) $this->menu->is_sold_out;
    }

    // Theme Studio B0.6: offers (MenuScheduleService::decorate() sets these
    // in-memory attributes on every item StoreController hands over).

    /** Pre-offer price when a schedule/happy-hour discount applies, else null. */
    public function original_price(): ?float
    {
        $orig = $this->menu->getAttribute('original_price');

        return is_numeric($orig) && (float) $orig > (float) $this->menu->price ? (float) $orig : null;
    }

    public function on_offer(): bool
    {
        return $this->original_price() !== null;
    }

    /** Rounded discount %, e.g. 20, or null when not on offer. */
    public function discount_percent(): ?int
    {
        $orig = $this->original_price();

        return $orig ? (int) round(100 - ((float) $this->menu->price / $orig) * 100) : null;
    }

    /** Offer/schedule label, e.g. "Happy hour". */
    public function schedule_label(): ?string
    {
        $label = $this->menu->getAttribute('schedule_label');

        return is_string($label) && $label !== '' ? $label : null;
    }

    /** False when the item is scheduled for a later time today. */
    public function available_now(): bool
    {
        return $this->menu->getAttribute('available_now') !== false;
    }

    /** e.g. "from 17:00" when not available now. */
    public function available_from_label(): ?string
    {
        $label = $this->menu->getAttribute('available_from_label');

        return is_string($label) && $label !== '' ? $label : null;
    }

    // Theme Studio B0.6: vertical fields (null/false where the store doesn't use them).

    /** 'in' | 'low' | 'out', or null when stock isn't tracked. */
    public function stock_status(): ?string
    {
        $qty = $this->stock_qty();
        if ($qty === null) {
            return null;
        }
        $low = (int) ($this->menu->low_stock_threshold ?? 5);

        return $qty <= 0 ? 'out' : ($qty <= $low ? 'low' : 'in');
    }

    /** Units in stock, only when the item tracks inventory. */
    public function stock_qty(): ?int
    {
        if (! $this->menu->track_inventory || $this->menu->stock_qty === null) {
            return null;
        }

        return (int) $this->menu->stock_qty;
    }

    /** SKU / barcode (PLU, else external SKU). */
    public function sku(): ?string
    {
        $sku = $this->menu->plu ?: $this->menu->external_sku;

        return $sku !== null && $sku !== '' ? (string) $sku : null;
    }

    /** 'each' | 'kg' (services/laundry pricing unit). */
    public function pricing_unit(): string
    {
        return $this->menu->pricing_unit === 'kg' ? 'kg' : 'each';
    }

    /** Service turnaround in hours (laundry/services), or null. */
    public function turnaround_hours(): ?int
    {
        return $this->menu->turnaround_hours !== null ? (int) $this->menu->turnaround_hours : null;
    }

    /** Expiry date (Y-m-d) for expiry-tracked goods, or null. */
    public function expiry_date(): ?string
    {
        $date = $this->menu->expiry_date; // cast to a date by the model

        return $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : null;
    }

    /** Age-restricted item (pharmacy/regulated goods). */
    public function age_restricted(): bool
    {
        return (bool) $this->menu->age_restricted;
    }

    /** Average diner rating (0 when unrated) — presentation + sort key. */
    public function rating(): float
    {
        return round((float) ($this->menu->rating_avg ?? 0), 2);
    }

    public function rating_count(): int
    {
        return (int) ($this->menu->rating_count ?? 0);
    }

    public function dietary_tags(): array
    {
        return (array) ($this->menu->dietary_tags ?? []);
    }

    // Child collections (built once per item)

    /**
     * Priced variant combinations with human labels. A variant's `options` is
     * a map {variantOptionId: choiceIdx}; the label joins the chosen labels
     * from the item's variant dimensions (`variantOptions`) — same algorithm
     * as the first-party themes. Variant prices are ABSOLUTE.
     */
    #[Cache]
    public function variants(): array
    {
        $dimensions = $this->rel('variantOptions')->keyBy('id');

        return $this->rel('variants')->map(function ($v) use ($dimensions) {
            $parts = [];
            foreach ((array) ($v->options ?? []) as $groupId => $idx) {
                // A clothing row stores the words themselves: {"Color":"White","Size":"S"}.
                // A legacy row stores {variantOptionId: choiceIndex}.
                if (is_string($groupId) && ! ctype_digit($groupId)) {
                    if (is_scalar($idx) && (string) $idx !== '') {
                        $parts[] = (string) $idx;
                    }

                    continue;
                }
                $group = $dimensions->get((int) $groupId);
                $choices = $group ? (array) $group->options : [];
                if (isset($choices[$idx])) {
                    $parts[] = $choices[$idx];
                }
            }

            return new VariantDrop($v, $parts !== [] ? implode(' · ', $parts) : null);
        })->all();
    }

    public function has_variants(): bool
    {
        return count($this->variants()) > 0;
    }

    /**
     * True when the item carries a variant matrix in the clothing shape —
     * rows whose `options` are the axis words themselves ({"Size":"M",
     * "Color":"Red"}). A legacy row ({variantOptionId: choiceIdx}) has no
     * matrix, so its "Add" is a quick add rather than a pick-size/colour
     * sheet. Same rule as CommerceRuntime::variantDims().
     */
    public function has_matrix(): bool
    {
        foreach ($this->rel('variants') as $variant) {
            foreach ((array) ($variant->options ?? []) as $key => $value) {
                if (is_string($key) && ! ctype_digit($key)) {
                    return true;
                }
            }
        }

        return false;
    }

    #[Cache]
    public function extras(): array
    {
        return $this->rel('extras')->map(fn ($e) => new ExtraDrop($e))->all();
    }

    /**
     * ADDITIVE option groups — the attached global (library) groups only.
     * Per-item `variantOptions` are variant DIMENSIONS (absolute-price combos,
     * exposed via variants()), not additive groups; including them here would
     * double-charge. Matches the first-party theme contract.
     */
    #[Cache]
    public function option_groups(): array
    {
        return $this->rel('globalGroups')
            ->map(fn ($g) => new OptionGroupDrop($g))
            ->all();
    }

    /**
     * Approved reviews for this item, newest first — eager-loaded by the
     * storefront render site (StoreController::publicView via Menu::reviews(),
     * an approved-only relation); the Drop performs no DB work. Pending/
     * rejected reviews and reviewer PII are never reachable here — see
     * ReviewsDrop.
     */
    /** @return list<ReviewsDrop> */
    #[Cache]
    public function reviews(): array
    {
        return $this->rel('reviews')->map(fn ($r) => new ReviewsDrop($r))->all();
    }
}
