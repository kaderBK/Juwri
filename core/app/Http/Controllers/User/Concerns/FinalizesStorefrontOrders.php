<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http\Controllers\User\Concerns;

use App\Mails\StoreOrderMail;
use App\Models\Order;
use App\Services\WhatsApp\OrderMessage;
use Illuminate\Support\Collection;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Juwri\Modules\Menu\Services\Availability\MenuScheduleService;
use Juwri\Modules\Payments\Models\Transaction;

/**
 * The storefront checkout's own helpers: the minimum-order subtotal, the
 * variant label, and the owner notification / WhatsApp / pay-online rows that
 * record a placed order.
 *
 * Extracted from StoreController to keep the controller under its size ceiling.
 */
trait FinalizesStorefrontOrders
{
    /**
     * B8-P1: the subtotal the minimum-order gate compares against, priced the same
     * way the order path will bill each line — the scheduled (or variant, which
     * replaces the base) unit price plus its paid extras — so a happy-hour basket is
     * judged on what it is actually charged, not its list price.
     *
     * @param  array<int, object>  $items
     * @param  array<int, array{unit_price: float, quantity: int}>  $resolvedCombos
     * @param  Collection<int, Menu>  $menusById
     */
    private function minimumOrderSubtotal(array $items, array $resolvedCombos, Collection $menusById, ?string $orderType, ?int $branchId): float
    {
        $subtotal = 0.0;
        foreach ($items as $item) {
            $menu = $menusById->get((int) ($item->id ?? 0));
            if (! $menu) {
                continue;
            }
            $qty = max(1, (int) ($item->quantity ?? 1));

            // Variant price replaces the base price when a valid variant is chosen.
            $chosenVariant = (isset($item->variants) && is_numeric($item->variants))
                ? MenuVariant::where('menu_id', $menu->id)->find($item->variants)
                : null;

            /* PARITY-RUN T5: resolve the unit through the SAME snapshot the order
               path persists (MenuScheduleService::snapshotFor). A schedule that
               applies REPLACES the base/variant price, exactly as the bill charges;
               extras are still added on top below. */
            $snapshot = MenuScheduleService::snapshotFor($menu, $chosenVariant, $orderType, $branchId);
            $unit = (float) $snapshot['unit_price'];

            /* Paid extras add to the unit — but only the ones this item
               actually offers. A bare find() here priced any extra id in the
               payload, so a foreign extra could carry a basket over the
               store's minimum, and (now that ordering drops those extras) the
               subtotal checked here would not be the subtotal billed. */
            $minAllowedExtras = $menu->resolved_extras->keyBy('id');
            foreach ((array) ($item->extras ?? []) as $extra) {
                $extraId = is_object($extra) ? ($extra->id ?? null) : $extra;
                $me = $extraId ? $minAllowedExtras->get((int) $extraId) : null;
                if ($me) {
                    $unit += (float) $me->price;
                }
            }
            $subtotal += $unit * $qty;
        }

        // T6: combos count toward the minimum — otherwise a store with a
        // minimum silently refused every combos-only basket.
        foreach ($resolvedCombos as $rc) {
            $subtotal += (float) $rc['unit_price'] * (int) $rc['quantity'];
        }

        return $subtotal;
    }

    /**
     * " (Large, Spicy)": one label per option group a variant points at, in the
     * variant's own order; empty when none resolve. A group the owner has since
     * deleted, or a pick the group no longer has, just adds nothing (it used to
     * 500 the checkout, mirrored from OrderItem::variantTitle).
     */
    private function variantTitleSuffix(MenuVariant $variant): string
    {
        $labels = [];
        foreach ((array) $variant->options as $groupId => $pick) {
            $group = MenuVariantOption::query()->whereKey($groupId)->first();
            if ($group === null) {
                continue;
            }
            $choices = $group->getOriginal('options');
            $choices = is_string($choices) ? json_decode($choices, true) : (array) $choices;
            if (is_array($choices) && isset($choices[$pick])) {
                $labels[] = $choices[$pick];
            }
        }

        return $labels === [] ? '' : ' ('.implode(', ', $labels).')';
    }

    /**
     * The owner's "new order" mail (settings template `new_order`), unless the
     * store switched it off (`store_submit_order_notification`, on by
     * default) or has no owner row.
     *
     * @param  array<string, mixed>  $details  the order fields StoreOrderMail merges
     */
    private function mailOwnerNewOrder(Store $store, array $details): void
    {
        $owner = $store->user;
        if ($owner === null || ! store_options($store->id, 'store_submit_order_notification', 1)) {
            return;
        }

        $owner->sendMail(new StoreOrderMail(['store_name' => $store->title] + $details));
    }

    /**
     * The store's WhatsApp order template (OrderMessage::SHORTCODES) expanded
     * with this order's values; `$values` is keyed by the tag name without braces.
     *
     * @param  array<string, mixed>  $values
     */
    private function whatsappOrderText(int $storeId, array $values): string
    {
        $tokens = [];
        foreach ($values as $tag => $value) {
            $tokens['{'.$tag.'}'] = $value;
        }

        return OrderMessage::build($storeId, $tokens);
    }

    /**
     * The pending payment row a pay-online storefront order is settled through
     * (PaymentController reads it by id). `user_id` holds the STORE id for
     * order payments, and both amounts are what is still owed.
     *
     * @param  array<string, mixed>  $details  hand-off data the payment page reads back
     */
    private function orderPaymentTransaction(Store $store, Order $order, float $due, ?string $ip, array $details): Transaction
    {
        $label = sprintf('%s (#%s)', $store->title, $order->getKey());

        return Transaction::create([
            'product_name' => $label,
            'transaction_description' => $label,
            'product_id' => $order->getKey(),
            'store_id' => $store->getKey(), // P1-04/P1-10: the store; user_id stays NULL (the diner is not a user)
            'amount' => $due,
            'base_amount' => $due,
            'currency_code' => store_options($store->getKey(), 'currency_code'),
            'transaction_method' => 'order',
            'transaction_ip' => $ip,
            'details' => $details,
        ]);
    }
}
