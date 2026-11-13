<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Menu\Services\Retail;

use Juwri\Core\Models\Store;
use Juwri\Core\Money\Money;
use Juwri\Modules\Menu\Contracts\RetailCatalog;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Models\SizeGuide;

/** P3-08: {@see RetailCatalog} over VariantMatrix, StockState and the size guides. */
final class RetailCatalogService implements RetailCatalog
{
    public function variantForPicks(Menu $item, array $picks): ?MenuVariant
    {
        return VariantMatrix::find($item, $picks);
    }

    public function axes(Menu $item): array
    {
        return VariantMatrix::axes($item);
    }

    public function cellLabel(Menu $item, MenuVariant $variant): string
    {
        $cell = $variant->cell();
        if ($cell !== null) {
            return VariantMatrix::label($item, $cell);
        }
        $labels = json_decode((string) json_encode($variant->options), true);

        return is_array($labels) ? implode(' · ', array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $labels)) : '';
    }

    public function stockState(Menu $item, ?MenuVariant $variant = null): array
    {
        if ($variant === null) {
            return StockState::forItem($item);
        }
        $cell = $variant->cell();
        $last = '';
        if ($cell !== null && $cell !== []) {
            // "Only 2 left in M": the last axis (size) names the cell.
            $last = VariantMatrix::label($item, array_slice($cell, -1, 1, true));
        }

        return StockState::forVariant($item, $variant, $last !== '' ? $last : null);
    }

    public function canSell(Menu $item, ?MenuVariant $variant, int $qty): bool
    {
        return StockState::canSell($item, $variant, $qty);
    }

    public function matrix(Store $store, Menu $item): array
    {
        $currency = strtoupper(store_currency((int) $store->id));
        $groups = [];
        $byTitle = [];
        foreach ($item->resolved_variant_options as $group) {
            $options = [];
            foreach ($group->modifiers->values() as $i => $modifier) {
                $options[$i] = ['public_id' => (string) $modifier->public_id, 'label' => mb_strtolower(trim((string) $modifier->title))];
            }
            $groups[(int) $group->id] = ['public_id' => (string) $group->public_id, 'options' => $options];
            $byTitle[mb_strtolower(trim((string) $group->getRawOriginal('title')))] = (int) $group->id;
        }
        $alerts = StockAlerts::enabled($store);

        $variants = $item->relationLoaded('variants') ? $item->variants : $item->variants()->get();
        $out = [];
        foreach ($variants as $variant) {
            /** @var MenuVariant $variant */
            if (! $variant->active || $variant->public_id === null) {
                continue;
            }
            $options = [];
            $cell = $variant->cell();
            if ($cell !== null) {
                foreach ($cell as $groupId => $index) {
                    if (isset($groups[$groupId]['options'][$index])) {
                        $options[$groups[$groupId]['public_id']] = $groups[$groupId]['options'][$index]['public_id'];
                    }
                }
            } else {
                // A label cell ({"Size": "M"}, VariantMatrixGenerator): matched to the item's groups by title and label.
                foreach ((array) json_decode((string) json_encode($variant->options), true) as $axis => $value) {
                    $groupId = $byTitle[mb_strtolower(trim((string) $axis))] ?? null;
                    if ($groupId === null || ! is_scalar($value)) {
                        continue;
                    }
                    foreach ($groups[$groupId]['options'] as $option) {
                        if ($option['label'] === mb_strtolower(trim((string) $value))) {
                            $options[$groups[$groupId]['public_id']] = $option['public_id'];
                        }
                    }
                }
            }
            $stock = $this->stockState($item, $variant);
            $price = Money::minorFromMajor((float) $variant->price, $currency);
            $compare = $variant->compare_at_minor !== null && (int) $variant->compare_at_minor > $price ? (int) $variant->compare_at_minor : null;
            $out[] = [
                'public_id' => (string) $variant->public_id,
                'sku' => $variant->sku !== null && $variant->sku !== '' ? (string) $variant->sku : null,
                'gtin' => $variant->gtin !== null && $variant->gtin !== '' ? (string) $variant->gtin : null,
                'options' => $options,
                'price_minor' => $price,
                'compare_at_minor' => $compare,
                'image' => $variant->image !== null && $variant->image !== '' ? (string) $variant->image : null,
                'stock' => $stock,
                'notify_available' => $alerts && $stock['state'] === 'out',
                'label' => $this->cellLabel($item, $variant),
            ];
        }

        return $out;
    }

    public function swatches(Menu $item): array
    {
        $out = [];
        foreach ($item->resolved_variant_options as $group) {
            if ($group->getAttribute('display') !== 'swatch') {
                continue;
            }
            foreach ($group->modifiers as $modifier) {
                $hex = (string) $modifier->getAttribute('swatch');
                if (preg_match('/^#[0-9A-Fa-f]{6}$/', $hex) === 1 && $modifier->public_id !== null) {
                    $out[(string) $modifier->public_id] = strtoupper($hex);
                }
            }
        }

        return $out;
    }

    public function sizeGuide(Store $store, Menu $item): ?SizeGuide
    {
        $id = $item->getAttribute('size_guide_id');
        if ($id === null) {
            return null;
        }

        return SizeGuide::query()->where('store_id', $store->id)->where('active', true)->find((int) $id);
    }

    public function sizeGuideById(Store $store, string $publicId): ?SizeGuide
    {
        return SizeGuide::query()->where('store_id', $store->id)->where('active', true)->where('public_id', $publicId)->first();
    }
}
