<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ordering\Services\Orders;

use App\Models\Order;
use App\Models\OrderItem;
use Juwri\Modules\Menu\Models\MenuVariantOption;

/**
 * H15: the storefront's confirmation/WhatsApp/owner-email order text, rendered
 * from the PERSISTED order rather than accumulated inside the write loop.
 *
 * Returns ['msg' => <html>, 'whatsapp' => <plain text>]. The money is NOT
 * recomputed here — Order::getTotalAttribute() is the sole authority
 * (CONVENTIONS §theme-totals) and callers read $order->total / $order->amount_due.
 *
 * The traversal (plain lines, variants, itemExtras, the choice-group `options`
 * snapshot and combo lines) mirrors EscPosRenderer::render(); the glyphs and
 * layout reproduce StoreController::submitOrder's legacy loop verbatim so saved
 * WhatsApp templates keep rendering identically:
 *   `▪️` per item, `▫️` per extra/modifier, `\t` indent for children,
 *   `' X '.$quantity` for the WhatsApp line and `' &times; '.$quantity` + `<br>`
 *   for the HTML line.
 */
class OrderTextRenderer
{
    private const ICON_ITEM = '▪️';

    private const ICON_CHILD = '▫️';

    /**
     * @return array{msg: string, whatsapp: string}
     */
    public static function render(Order $order): array
    {
        $order_msg = '';
        $order_whatsapp_detail = '';

        // WI-H15 trap: the modifier "(+2.00)" label follows the STORE's currency
        // decimals (a 3-decimal store's 0.275 OMR must not read "(+0.28)"). Resolve
        // from the order's restaurant explicitly — submitOrder overwrites
        // config('settings.currency_*') around its response, so a bare
        // currency_decimals() would silently yield the platform's 2.
        $priceDecimals = currency_decimals(store_options($order->store_id, 'currency_code') ?: null);

        // N+1: preload every referenced variant-option group in one query so
        // variantTitle() resolves from a map instead of a find() per option per line.
        $optionIds = [];
        foreach ($order->items as $item) {
            if ($item->variant) {
                foreach (array_keys((array) $item->variant->options) as $oid) {
                    $optionIds[] = $oid;
                }
            }
        }
        $variantOptionMap = empty($optionIds)
            ? collect()
            : MenuVariantOption::whereIn('id', array_values(array_unique($optionIds)))->get()->keyBy('id');

        foreach ($order->items as $item) {
            if ($item->isCombo()) {
                $snapshot = $item->comboSnapshot();
                $qty = (int) $item->quantity;
                $comboName = $item->displayName();

                $order_msg .= $comboName.($qty > 1 ? ' &times; '.$qty : '').'<br>';
                $order_whatsapp_detail .= self::ICON_ITEM.$comboName.' X '.$qty."\n";
                foreach ($snapshot['components'] as $component) {
                    $order_msg .= '&nbsp;&nbsp;'.$component['name'].'<br>';
                    $order_whatsapp_detail .= "\t".self::ICON_CHILD.$component['name']."\n";
                }
                $order_msg .= '<br>';

                continue;
            }

            $menu = $item->menu;
            if (! $menu) {
                continue;
            }

            $qty = (int) $item->quantity;
            $name = (string) $menu->getRawOriginal('name');
            $variantTitle = self::variantTitle($item, $variantOptionMap);

            $order_msg .= $name.$variantTitle.($qty > 1 ? ' &times; '.$qty : '').'<br>';
            $order_whatsapp_detail .= self::ICON_ITEM.$name.$variantTitle.' X '.$qty."\n";

            // Paid extras (itemExtras), in persisted order.
            foreach ($item->itemExtras as $itemExtra) {
                if ($itemExtra->extra) {
                    $title = (string) $itemExtra->extra->getRawOriginal('title');
                    $order_msg .= $title.'<br>';
                    $order_whatsapp_detail .= "\t".self::ICON_CHILD.$title."\n";
                }
            }

            // Choice-group / modifier selections from the `options` snapshot.
            foreach ((array) $item->options as $grp) {
                $groupTitle = (string) ($grp['title'] ?? '');
                foreach ((array) ($grp['selected'] ?? []) as $opt) {
                    $optLabel = (string) ($opt['option'] ?? '');
                    $optPrice = (float) ($opt['price'] ?? 0);
                    $line = $groupTitle.': '.$optLabel
                        .($optPrice > 0 ? ' (+'.number_format($optPrice, $priceDecimals).')' : '');
                    $order_msg .= $line.'<br>';
                    $order_whatsapp_detail .= "\t".self::ICON_CHILD.$line."\n";
                }
            }

            $order_msg .= '<br>';
        }

        return ['msg' => $order_msg, 'whatsapp' => $order_whatsapp_detail];
    }

    /**
     * Reproduce submitOrder's ` (opt1, opt2)` variant label from the persisted line's
     * variant, matching the legacy loop (a vanished option group contributes no
     * fragment).
     *
     * @param  \Illuminate\Support\Collection<array-key, MenuVariantOption>  $optionMap  variant-option groups keyed by id
     */
    private static function variantTitle(OrderItem $item, $optionMap): string
    {
        if (! $item->variation) {
            return '';
        }
        $variant = $item->variant;
        if (! $variant) {
            return '';
        }

        $fragments = [];
        foreach ((array) $variant->options as $optionId => $optionKey) {
            $group = $optionMap->get($optionId);
            if (! $group) {
                continue;
            }
            $options = $group->getOriginal('options');
            $options = is_string($options) ? json_decode($options, true) : (array) $options;
            if (is_array($options) && isset($options[$optionKey])) {
                $fragments[] = $options[$optionKey];
            }
        }

        return ! empty($fragments) ? ' ('.implode(', ', $fragments).')' : '';
    }
}
