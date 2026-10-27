<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\ViewModel;

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Verticals\VerticalRegistry;
use Carbon\Carbon;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Money;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Delivery\Models\Shipment;
use Juwri\Modules\Menu\Contracts\GroceryCatalog;
use Juwri\Modules\Menu\Contracts\RetailCatalog;
use Juwri\Modules\Ordering\Data\StorefrontQuote;
use Juwri\Modules\Storefront\Services\Api\StorefrontOrderView;

/**
 * P3-02: the money and order payloads of the view model — Quote (cart, checkout, track), the track page's Order
 * (number, steps, actions) — from the Ordering module's StorefrontQuote. Every amount is a server-formatted Money;
 * the charged rows add up to the total exactly (StorefrontQuote::sumOfRows), informational rows are flagged.
 */
final class OrderPresenter
{
    public function __construct(
        private readonly Store $store,
        private readonly MoneyFormat $money,
        private readonly string $timezone,
    ) {}

    /** @return array<string, mixed> Quote */
    public function quote(StorefrontQuote $q, string $totalLabel): array
    {
        $lines = [];
        $compare = $this->compareAtTotals($q);
        foreach ($q->order->items->values() as $i => $item) {
            /** @var OrderItem $item */
            $line = $this->line($item, $i, $q->currency);
            if (isset($compare['lines'][$i])) {
                $line['compare_at'] = $this->money->money($compare['lines'][$i]);
            }
            $lines[] = $line;
        }

        return ['lines' => $lines] + $this->totals($q, $totalLabel);
    }

    /**
     * P3-08: a goods line on sale shows its compare-at price (times the quantity); the quote adds a "You save" row with
     * what the compare-at prices take off. Shared by quote() and totals() (P3-13: a legacy theme shows only the totals).
     *
     * @return array{lines: array<int, int>, saving: int} compare-at minor amounts by line index, and the total saving
     */
    private function compareAtTotals(StorefrontQuote $q): array
    {
        $out = ['lines' => [], 'saving' => 0];
        if ($this->store->isFoodVertical()) {
            return $out;
        }
        foreach ($q->order->items->values() as $i => $item) {
            /** @var OrderItem $item */
            $compare = $this->compareAtMinor($item, $q->currency);
            $qty = (int) $item->quantity;
            $lineMinor = Money::minorFromMajor($this->unitMajor($item) * $qty, $q->currency);
            if ($compare !== null && $compare * $qty > $lineMinor) {
                $out['lines'][$i] = $compare * $qty;
                $out['saving'] += $compare * $qty - $lineMinor;
            }
        }

        return $out;
    }

    /**
     * The charged rows, the total and the coupon of a quote — without the lines. P3-13: what a legacy theme shows
     * (it lists its own cart lines; every amount below the lines is the server's).
     *
     * @return array<string, mixed>
     */
    public function totals(StorefrontQuote $q, string $totalLabel): array
    {
        $rows = [['key' => 'subtotal', 'label' => ___('Subtotal'), 'amount' => $this->money->money($q->subtotal), 'kind' => 'subtotal']];
        if ($q->order->type === 'delivery') {
            $row = ['key' => 'delivery', 'label' => ___('Delivery'), 'amount' => $this->money->money($q->delivery), 'kind' => 'delivery'];
            if ($q->delivery === 0) {
                $row['amount_label'] = ___('Free');
            }
            $rows[] = $row;
            if (($q->freeDeliveryRemaining ?? 0) > 0) {
                // the store's "free delivery over X": what the basket still needs (never charged, never in the total)
                $rows[] = ['key' => 'free_delivery_remaining', 'label' => ___('Add :amount more for free delivery', ['amount' => (string) $this->money->money($q->freeDeliveryRemaining)['formatted']]),
                    'amount' => $this->money->money($q->freeDeliveryRemaining), 'kind' => 'delivery', 'muted' => true, 'informational' => true];
            }
        }
        if ($q->order->type === 'ship' && ($q->shipping !== null || $q->shippingLabel !== null || $q->delivery > 0)) {
            // P3-08: the shipping method's fee is the order's delivery charge.
            $row = [
                'key' => 'shipping',
                'label' => $q->shippingLabel !== null && $q->shippingLabel !== '' ? ___('Shipping · :service', ['service' => $q->shippingLabel]) : ___('Shipping'),
                'amount' => $this->money->money($q->delivery),
                'kind' => 'delivery',
            ];
            if ($q->delivery === 0) {
                $row['amount_label'] = ___('Free');
            }
            $rows[] = $row;
        }
        if ($q->fee > 0) {
            $rows[] = ['key' => 'room_service', 'label' => ___('Room service charge'), 'amount' => $this->money->money($q->fee), 'kind' => 'fee'];
        }
        if ($q->service > 0) {
            $rows[] = ['key' => 'service', 'label' => ___('Service charge'), 'amount' => $this->money->money($q->service), 'kind' => 'fee'];
        }
        // J32: each pre-VAT levy (municipality fee, stamp duty…) on its own line, before the tax
        foreach ($q->levies as $levy) {
            $rows[] = ['key' => 'levy_'.$levy['id'], 'label' => $levy['name'], 'amount' => $this->money->money($levy['amount']), 'kind' => 'fee'];
        }
        if ($q->tax > 0) {
            $rows[] = ['key' => 'tax', 'label' => $this->taxLabel(), 'amount' => $this->money->money($q->tax), 'kind' => 'tax'];
        }
        if ($q->tip > 0) {
            $rows[] = ['key' => 'tip', 'label' => ___('Tip'), 'amount' => $this->money->money($q->tip), 'kind' => 'tip'];
        }
        if ($q->donation > 0) {
            $rows[] = ['key' => 'donation', 'label' => ___('Donation'), 'amount' => $this->money->money($q->donation), 'kind' => 'fee'];
        }
        if ($q->discount > 0) {
            $rows[] = [
                'key' => 'discount',
                'label' => $q->couponApplied && $q->couponCode !== null ? ___('Discount · :code', ['code' => $q->couponCode]) : ___('Discount'),
                'amount' => $this->money->money(-$q->discount),
                'kind' => 'discount',
            ];
        }

        // P3-09: weighed lines not yet weighed may cost up to their cap (shown, never in the total; basket §9)
        $headroom = $this->weighedHeadroomMinor($q);
        if ($headroom > 0) {
            $rows[] = ['key' => 'estimate_cap', 'label' => ___('Weighed items can add up to'), 'amount' => $this->money->money($headroom), 'kind' => 'estimate_cap', 'muted' => true, 'informational' => true];
            $totalLabel = ___('Estimated total');
        }

        $saving = $this->compareAtTotals($q)['saving'];
        if ($saving > 0) {
            // Not charged: what the compare-at prices take off (informational, never in the total).
            $rows[] = ['key' => 'saving', 'label' => ___('You save'), 'amount' => $this->money->money($saving), 'kind' => 'discount', 'muted' => true, 'informational' => true];
        }

        $quote = ['rows' => $rows, 'total' => $this->money->money($q->total), 'total_label' => $totalLabel];
        if ($q->couponCode !== null) {
            $quote['coupon'] = array_filter(
                ['code' => $q->couponCode, 'applied' => $q->couponApplied, 'message' => $q->couponMessage],
                fn ($v) => $v !== null
            );
        }

        return $quote;
    }

    /** @return array<string, mixed> Order (track page) */
    public function order(Order $order, StorefrontQuote $q): array
    {
        $number = $this->number($order);
        $steps = $this->steps($order);
        $paid = $this->paidLabel($order);

        $out = [
            'number' => $number,
            'status' => (string) $order->status,
            'status_label' => $this->statusLabel($order),
            'heading' => ___('Order no. :number', ['number' => $number]),
            'steps' => $steps,
            'quote' => $this->quote($q, ___('Total · :method', ['method' => $paid])),
            'paid_label' => $paid,
        ];
        // P3-08: a shipped parcel shows its carrier and tracking link (the shipping method's template).
        if ($order->getRawOriginal('type') === 'ship') {
            $shipment = Shipment::query()->where('order_id', $order->id)->first();
            if ($shipment !== null && ($shipment->carrier || $shipment->tracking_number)) {
                $out['carrier'] = array_filter([
                    'name' => (string) $shipment->carrier,
                    'tracking' => (string) $shipment->tracking_number,
                    'url' => (string) $shipment->tracking_url,
                ], fn ($v) => $v !== '');
            }
        }

        $actions = [];
        $phone = preg_replace('/[^\d+]/', '', (string) $this->store->phone);
        if ($phone !== '' && $phone !== null) {
            $actions[] = ['label' => ___('Call store'), 'url' => 'tel:'.$phone, 'kind' => 'secondary', 'icon' => 'phone'];
        }
        $whatsapp = storefront_whatsapp_number($this->store, null, false);
        if ($whatsapp !== '') {
            $actions[] = ['label' => ___('WhatsApp'), 'url' => 'https://wa.me/'.$whatsapp, 'kind' => 'secondary', 'icon' => 'message-circle'];
        }
        if ($actions !== []) {
            $out['actions'] = $actions;
        }

        return $out;
    }

    /**
     * P3-09 (basket §10 "Changes to review"): the replacements the picker proposed and the shopper has not answered, one
     * quote line each — the replacement's name and price, what it replaces. The line id is the quote's own (`ln_N`, the
     * position in the order), never a row id; the answer is posted against it.
     *
     * @return list<array<string, mixed>>
     */
    public function review(StorefrontQuote $q): array
    {
        if ($this->store->isFoodVertical()) {
            return [];
        }
        $out = [];
        foreach ($q->order->items->values() as $i => $item) {
            /** @var OrderItem $item */
            if ((string) $item->getAttribute('pick_status') !== 'proposed') {
                continue;
            }
            $was = $this->unitMajor($item) * (int) $item->quantity;
            $row = [
                'id' => 'ln_'.($i + 1),
                'name' => (string) $item->getAttribute('replacement_name'),
                'qty' => (int) $item->quantity,
                'line_total' => $this->money->money((int) $item->getAttribute('replacement_minor')),
                'substitution' => ___('Replaces :name · :price', ['name' => (string) ($item->menu->name ?? ''), 'price' => $this->money->format(Money::minorFromMajor($was, $q->currency))]),
                'note' => ___('Refund instead or keep the replacement'),
            ];
            if ($item->menu !== null && $item->menu->public_id !== null) {
                $row = ['id' => $row['id'], 'item_id' => (string) $item->menu->public_id] + array_slice($row, 1);
            }
            $out[] = $row;
        }

        return $out;
    }

    /** Short order number shown to the shopper: the daily token, else the tail of the public id (never the row id). */
    public function number(Order $order): string
    {
        if ($order->token_number) {
            return (string) $order->token_number;
        }

        return strtoupper(substr(str_replace('-', '', (string) $order->public_id), -6));
    }

    public function time(?Carbon $at): ?string
    {
        return $at?->copy()->setTimezone($this->timezone)->locale(app()->getLocale())->translatedFormat('g:i A');
    }

    /** @return array<string, mixed> */
    private function line(OrderItem $item, int $index, string $currency): array
    {
        $menu = $item->menu;
        $unit = $this->unitMajor($item);
        $labels = [];
        if ($item->variation && $item->variant) {
            // P3-08: a matrix cell shows its option labels ("Sand · M"); other variants their axes label.
            $variantLabel = $menu !== null && $item->variant->cell() !== null
                ? app(RetailCatalog::class)->cellLabel($menu, $item->variant)
                : trim((string) $item->variant->axes_label);
            if ($variantLabel !== '') {
                $labels[] = $variantLabel;
            }
        }
        foreach ((array) $item->options as $group) {
            foreach ((array) ($group['selected'] ?? []) as $selected) {
                $labels[] = (string) ($selected['option'] ?? '');
            }
        }
        foreach ($item->itemExtras as $itemExtra) {
            $title = $itemExtra->extra?->title;
            if ($title) {
                $labels[] = (string) $title;
            }
        }

        $lineMinor = Money::minorFromMajor($unit * (int) $item->quantity, $currency);
        $final = $item->getAttribute('final_minor');
        $line = [
            'id' => 'ln_'.($index + 1),
            'name' => (string) ($menu->name ?? ''),
            'qty' => (int) $item->quantity,
            // P3-09: a line the picker settled (actual weight, kept replacement, removed) is worth its final amount
            'line_total' => $this->money->money($final !== null ? (int) $final : $lineMinor),
        ];
        if ($menu !== null && $menu->public_id !== null) {
            $line = ['id' => $line['id'], 'item_id' => (string) $menu->public_id] + $line;
            if (! empty($menu->image) && $menu->image !== 'default.png') {
                $line['thumb'] = $this->cardImage((string) $menu->image, (string) $menu->name);
            }
        }
        $labels = array_values(array_filter($labels, fn (string $l) => $l !== ''));
        if ($item->weight_kg !== null) {
            // P3-11 (laundry): a weighed line is one batch priced at price per kg x kg; the shopper reads the weight
            $labels = [___(':kg kg', ['kg' => rtrim(rtrim(number_format((float) $item->weight_kg, 3, '.', ''), '0'), '.')]), ...$labels];
        }
        if ($labels !== []) {
            $line['choices_label'] = implode(' · ', $labels);
        }
        $note = $item->customerNote();
        if ($note !== '') {
            $line['note'] = str_replace("\n", ' · ', $note);
        }

        return $this->groceryLine($line, $item);
    }

    /**
     * P3-09 (basket): a weighed line is an estimate up to its cap until the picker weighs it; a pack shows its size; a
     * picked line shows what happened (actual weight, replacement, removal).
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function groceryLine(array $line, OrderItem $item): array
    {
        if ($this->store->isFoodVertical()) {
            return $line;
        }
        $grams = $item->getAttribute('weight_g');
        $final = $item->getAttribute('final_minor');
        $status = (string) $item->getAttribute('pick_status');
        if ($grams !== null) {
            $line['unit_label'] = ___(':weight · by weight', ['weight' => app(GroceryCatalog::class)->stepLabel((int) $grams)]);
            if ($final === null) {
                $line['estimate'] = true;
                $line['note'] = GroceryPresenter::lineNote($this->money, (int) $item->getAttribute('cap_minor'));
            } elseif ($item->getAttribute('actual_g') !== null && $status === 'picked') {
                $line['note'] = ___('Weighed :weight', ['weight' => app(GroceryCatalog::class)->stepLabel((int) $item->getAttribute('actual_g'))]);
            }
        } else {
            $attributes = json_decode((string) json_encode($item->menu?->getAttribute('attributes')), true);
            if (is_array($attributes) && is_numeric($attributes['pack_size'] ?? null) && is_string($attributes['pack_unit'] ?? null)) {
                $line['unit_label'] = trim(rtrim(rtrim((string) $attributes['pack_size'], '0'), '.').' '.$attributes['pack_unit']);
            }
        }
        if ($status === 'removed') {
            $line['warning'] = ___('Out of stock — removed');
        } elseif ($status === 'replaced' || $status === 'proposed') {
            $line['substitution'] = ___('Replacement: :name', ['name' => (string) $item->getAttribute('replacement_name')]);
        }

        return $line;
    }

    /**
     * P3-09: what the weighed lines not yet weighed may add on top of the total (their caps − their estimates), minor
     * units; 0 when there is none.
     */
    public function weighedHeadroomMinor(StorefrontQuote $q): int
    {
        $headroom = 0;
        foreach ($q->order->items as $item) {
            /** @var OrderItem $item */
            if ($item->getAttribute('weight_g') === null || $item->getAttribute('final_minor') !== null) {
                continue;
            }
            $estimate = Money::minorFromMajor($this->unitMajor($item) * (int) $item->quantity, $q->currency);
            $headroom += max(0, (int) $item->getAttribute('cap_minor') - $estimate);
        }

        return $headroom;
    }

    /**
     * P3-09: the estimate and the cap of the weighed lines not yet weighed (kit/api QuoteResponse.weighed), or null.
     *
     * @return array{estimate: int, cap: int}|null
     */
    public function weighedTotals(StorefrontQuote $q): ?array
    {
        $estimate = 0;
        $cap = 0;
        $any = false;
        foreach ($q->order->items as $item) {
            /** @var OrderItem $item */
            if ($item->getAttribute('weight_g') === null || $item->getAttribute('final_minor') !== null) {
                continue;
            }
            $any = true;
            $estimate += Money::minorFromMajor($this->unitMajor($item) * (int) $item->quantity, $q->currency);
            $cap += (int) $item->getAttribute('cap_minor');
        }

        return $any ? ['estimate' => $estimate, 'cap' => $cap] : null;
    }

    /** A line's unit price in major units: the base (or variant) price plus its chosen options and extras. */
    private function unitMajor(OrderItem $item): float
    {
        $menu = $item->menu;
        $unit = $item->unit_price !== null
            ? (float) $item->unit_price
            : (float) ($item->variation && $item->variant ? $item->variant->price : ($menu->price ?? 0));
        foreach ((array) $item->options as $group) {
            foreach ((array) ($group['selected'] ?? []) as $selected) {
                $unit += (float) ($selected['price'] ?? 0);
            }
        }
        foreach ($item->itemExtras as $itemExtra) {
            $unit += (float) ($itemExtra->price ?? optional($itemExtra->extra)->price ?? 0);
        }

        return $unit;
    }

    /** P3-08: the unit compare-at price of a goods line (its matrix cell's, else the item's), minor units. */
    private function compareAtMinor(OrderItem $item, string $currency): ?int
    {
        if ($item->variation && $item->variant && $item->variant->compare_at_minor !== null) {
            return (int) $item->variant->compare_at_minor;
        }
        $menu = $item->menu;
        if ($menu === null || ($item->variation && $item->variant)) {
            return null;
        }
        $attributes = json_decode((string) json_encode($menu->getAttribute('attributes')), true);
        $compare = is_array($attributes) ? ($attributes['compare_at_price'] ?? null) : null;

        return is_numeric($compare) && (float) $compare > 0 ? Money::minorFromMajor((float) $compare, $currency) : null;
    }

    private function taxLabel(): string
    {
        $label = trim(StoreSettings::string((int) $this->store->id, 'restaurant_tax_label'));
        $label = $label !== '' ? $label : ___('Tax');
        $type = (string) (StoreSettings::get((int) $this->store->id, 'restaurant_tax_type') ?: 'percentage');
        $rate = StoreSettings::get((int) $this->store->id, 'restaurant_taxx_charge');
        if ($type !== 'fixed' && is_numeric($rate) && (float) $rate > 0) {
            return $label.' '.rtrim(rtrim(number_format((float) $rate, 2, '.', ''), '0'), '.').'%';
        }

        return $label;
    }

    /**
     * P6-303: the track-page label is derived from the same publicStatus() key the headless API uses, so the JSON
     * status and the track page never drift. The retail states (packing / shipped / collected / done) and the
     * delivery-status 'Out for delivery' override are kept first — publicStatus may not know about them.
     */
    private function statusLabel(Order $order): string
    {
        // P3-08 / P3-11: when the courier has the order, the track label tracks the in-flight handoff, not the
        // raw order status (a `shipped` row that hasn't reached the courier yet reads the same).
        if ($order->type === 'delivery' && in_array($order->delivery_status, ['out_for_delivery', 'picked_up'], true)
            && ! in_array($order->status, ['delivered', 'completed', 'cancelled'], true)) {
            return ___('Out for delivery');
        }

        // Retail states (RetailOrderBoardService): publicStatus maps them to `placed`, so keep their own labels.
        $status = (string) $order->status;
        $retail = match ($status) {
            'packing' => ___('Processing'),
            'shipped' => (string) $order->type === 'ship' ? ___('Shipped') : ___('Out for delivery'),
            'collected' => ___('Collected'),
            'done' => ___('Done'),
            default => null,
        };
        if ($retail !== null) {
            return $retail;
        }

        // Derive every other label from the publicStatus() key the JSON API exposes, so they stay in lockstep.
        return match (StorefrontOrderView::publicStatus($order)) {
            'pending_payment' => ___('Payment not completed'),
            'pending_approval' => ___('Pending approval'),
            'placed' => ___('Order placed'),
            'accepted' => ___('Accepted'),
            'preparing' => ___('Preparing'),
            'ready' => ___('Ready'),
            'on_the_way' => ___('Out for delivery'),
            'shipped' => ___('Shipped'),
            'delivered' => ___('Delivered'),
            'completed' => ___('Completed'),
            'cancelled' => ___('Cancelled'),
            'refunded' => ___('Refunded'),
            default => ___('Order placed'),
        };
    }

    private function paidLabel(Order $order): string
    {
        if ($order->is_paid) {
            return ___('Paid');
        }
        if (in_array($order->payment_method, [null, '', 'cash', 'cod'], true)) {
            return $order->type === 'delivery' ? ___('Cash on delivery') : ___('Pay at the counter');
        }

        return ___('Unpaid');
    }

    /**
     * Progress steps for the order's fulfilment: each step done, current (the latest reached, while the order is
     * live) or to do, with the time it was reached.
     *
     * @return list<array{label: string, sub?: string, state: string}>
     */
    private function steps(Order $order): array
    {
        // P6-303: a cancelled or refunded order has no progress steps — its only state is the terminal one the
        // status label already shows.
        if (in_array((string) $order->status, ['cancelled', 'refunded'], true)) {
            return [];
        }
        $type = (string) $order->type;
        $service = in_array($type, ['pickup_slot', 'delivery'], true) && app(VerticalRegistry::class)->has($this->store->store_type ?? null, 'service');
        // a grocery order carries a slot, an out-of-stock choice or a weighed line (picking starts later)
        $grocery = ! $service && ! $this->store->isFoodVertical() && in_array($type, ['delivery', 'pickup_slot'], true)
            && ((string) $this->store->store_type === 'grocery' || $order->getAttribute('slot_starts_at') !== null || $order->getAttribute('substitution_option_id') !== null || $order->items->contains(fn ($item) => $item->getAttribute('weight_g') !== null));
        $steps = match (true) {
            // P3-11 (laundry): received -> processing -> ready (collected) or out for delivery -> done
            $service && $type === 'delivery' => [
                [___('Booked'), $order->created_at], [___('Processing'), $order->packing_at],
                [___('Out for delivery'), $order->shipped_at], [___('Delivered'), $order->completed_at],
            ],
            $service => [
                [___('Booked'), $order->created_at], [___('Processing'), $order->packing_at],
                [___('Ready'), $order->ready_at], [___('Collected'), $order->collected_at ?? $order->completed_at],
            ],
            $type === 'ship' => [
                [___('Order placed'), $order->created_at], [___('Preparing'), $order->packing_at ?? $order->preparing_at],
                [___('Shipped'), $order->shipped_at], [___('Delivered'), $order->delivered_at],
            ],
            // P3-09 (basket): a grocery order is picked off the shelves before it goes out
            $grocery && $type === 'delivery' => [
                [___('Order placed'), $order->created_at], [___('Picking'), $order->preparing_at],
                [___('On the way'), $order->out_for_delivery_at], [___('Delivered'), $order->delivered_at ?? $order->completed_at],
            ],
            $grocery => [
                [___('Order placed'), $order->created_at], [___('Picking'), $order->preparing_at],
                [___('Ready'), $order->ready_at], [___('Completed'), $order->completed_at ?? $order->collected_at],
            ],
            $type === 'delivery' => [
                [___('Order placed'), $order->created_at], [___('Preparing'), $order->preparing_at],
                [___('Out for delivery'), $order->out_for_delivery_at], [___('Delivered'), $order->delivered_at ?? $order->completed_at],
            ],
            in_array($type, ['appointment', 'at_home'], true) => [
                [___('Booked'), $order->created_at], [___('Confirmed'), $order->preparing_at], [___('Completed'), $order->completed_at],
            ],
            default => [
                [___('Order placed'), $order->created_at], [___('Preparing'), $order->preparing_at],
                [___('Ready'), $order->ready_at], [___('Completed'), $order->completed_at ?? $order->collected_at],
            ],
        };

        $last = count($steps) - 1;
        $reached = 0;
        foreach ($steps as $i => [, $at]) {
            if ($at !== null) {
                $reached = $i;
            }
        }
        if ($grocery && in_array((string) $order->getAttribute('picking_status'), ['picking', 'picked'], true) && $order->delivered_at === null && $order->out_for_delivery_at === null) {
            $reached = max($reached, 1); // the picker has started: the order is being picked, whatever the order status says
        }
        $reached = max($reached, match ((string) $order->status) {
            'preparing', 'packing' => 1,
            'ready', 'shipped' => min(2, $last),
            'collected' => $service ? $last : min(2, $last),
            'delivered', 'completed', 'done' => $last,
            default => 0,
        });
        $final = in_array((string) $order->status, ['delivered', 'completed', 'done'], true) || ($service && (string) $order->status === 'collected');

        $out = [];
        foreach ($steps as $i => [$label, $at]) {
            $state = $i < $reached || ($final && $i === $reached) ? 'done' : ($i === $reached ? 'current' : 'todo');
            $row = ['label' => $label];
            $time = $at instanceof Carbon ? $this->time($at) : null;
            if ($time !== null && $state !== 'todo') {
                $row['sub'] = $time;
            }
            if ($grocery && $i === 1 && $state === 'current') {
                // basket §10: how far the picker is — lines settled (picked, replaced or removed) of all lines
                $total = $order->items->count();
                $settled = $order->items->filter(fn ($item) => in_array((string) $item->getAttribute('pick_status'), ['picked', 'replaced', 'removed'], true))->count();
                $row['sub'] = ___(':picked of :total items picked', ['picked' => (string) $settled, 'total' => (string) $total]);
            }
            $out[] = $row + ['state' => $state];
        }

        return $out;
    }

    /** P6-308: a card / thumb image: width variants (srcset) when they exist locally, else the original upload. */
    /** @return array<string, string> */
    private function cardImage(string $file, string $alt): array
    {
        $v = media_card_variants('storage/menu/', $file, (int) $this->store->id);

        return $v !== null
            ? ['src' => $v['src'], 'srcset' => $v['srcset'], 'alt' => $alt]
            : ['src' => media_url('storage/menu/', $file, (int) $this->store->id), 'alt' => $alt];
    }
}
