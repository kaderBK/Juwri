<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http\Controllers\User\Concerns;

use App\Models\OrderItem;
use Juwri\Core\Models\Store;

/**
 * Builds the cart-line payload a storefront needs to rebuild an order.
 *
 * Extracted from CustomerAuthController so the GUEST lookup can return the same
 * shape. It previously returned only id/token/total/status/date, with no line
 * items at all, which is why guest reorder could not work in any theme -- there
 * was nothing to reorder from.
 */
trait BuildsReorderPayload
{
    protected function buildReorderItemPayload(OrderItem $orderItem, Store $store): ?array
    {
        $menu = $orderItem->menu;
        if (! $menu || (string) $menu->active !== '1' || (int) ($menu->is_sold_out ?? 0) === 1) {
            return null;
        }

        $variant = null;
        if (! empty($orderItem->variation)) {
            $variant = $orderItem->variant;
            if (! $variant || (int) ($variant->active ?? 0) !== 1) {
                return null;
            }
        }

        $extras = [];
        foreach ($orderItem->itemExtras as $itemExtra) {
            $extra = $itemExtra->extra;
            if (! $extra || (int) ($extra->active ?? 0) !== 1) {
                continue;
            }

            $extras[] = [
                'id' => (int) $extra->id,
                'title' => $extra->title,
                'price' => (float) $extra->price,
            ];
        }

        /* Choice-group selections, forwarded verbatim in the shape OrderApiService
           snapshots them and Order::getTotalAttribute() reads back:
             [['group_id' => int, 'title' => string,
               'selected' => [['option' => string, 'price' => float], …]], …]
           Without this a reorder silently dropped every global-modifier choice AND
           under-priced the line, because the option prices are part of what the
           server charges. Themes that don't read `options` are unaffected; those
           that do must include the same prices in their own line total (see
           rev/theme-rescue/TOTALS-CONTRACT.md). */
        $options = (array) ($orderItem->options ?? []);
        $optionsPrice = 0.0;
        foreach ($options as $grp) {
            foreach ((array) ($grp['selected'] ?? []) as $opt) {
                $optionsPrice += (float) ($opt['price'] ?? 0);
            }
        }

        $note = trim((string) ($orderItem->order_note ?? ''));
        $extraIds = collect($extras)->pluck('id')->sort()->implode(',');
        /* Options belong in the merge key: two lines of the same dish differing only
           by a choice-group selection are different lines, not one line of qty 2. */
        $optionsKey = md5(json_encode($options));

        return [
            'key' => $menu->id.'|'.($variant->id ?? 0).'|'.$extraIds.'|'.rawurlencode($note).'|'.$optionsKey,
            'id' => (int) $menu->id,
            'name' => $menu->name,
            'img' => media_url('storage/menu/', $menu->image, $store->id ?? null),
            'unitBase' => (float) ($variant->price ?? $menu->price) + $optionsPrice,
            'variantId' => (int) ($variant->id ?? 0),
            'variantLabel' => $orderItem->variation_title,
            'extras' => $extras,
            'options' => $options,
            'qty' => (int) $orderItem->quantity,
            'order_note' => $note,
        ];
    }
}
