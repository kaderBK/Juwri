<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\ViewModel;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Data\StorefrontQuote;

/**
 * P3-11 — the `book` theme's view model for a laundry (a service store that sells per piece and per kg and picks up and
 * delivers): the catalogue is the menu's, each card says what it is priced by, and the two steps after choosing — the
 * pickup (and delivery) times, and the confirm page — come from what the laundry flow computed (LaundryThemeController).
 * Money is server-formatted; every total is the server's quote of the cart (GoodsCartQuote, the booking's own pricing).
 * Never reads a request.
 */
final class LaundryViewModel
{
    public function __construct(private readonly Store $store, private readonly OrderPresenter $orders) {}

    /**
     * The catalogue's cards, each saying what it is priced by: a piece, or a kilo (the shopper gives the weight).
     *
     * @param  array{categories: list<array<string, mixed>>, items: array<string, array<string, mixed>>, total: int}  $catalog
     * @param  Collection<int, Menu>  $items
     * @return array{categories: list<array<string, mixed>>, items: array<string, array<string, mixed>>, total: int}
     */
    public function catalog(array $catalog, Collection $items): array
    {
        $byId = $items->keyBy(fn (Menu $m) => (string) $m->public_id);
        foreach ($catalog['items'] as $id => $card) {
            $menu = $byId[$id] ?? null;
            if ($menu === null || ! isset($card['price'])) {
                continue;
            }
            $kg = $menu->pricing_unit === 'kg';
            $amount = (string) $card['price']['formatted'];
            $catalog['items'][$id]['unit'] = $kg
                ? ['label' => ___(':amount per kg', ['amount' => $amount]), 'sold_by_weight' => true, 'step_g' => 500]
                : ['label' => ___(':amount per piece', ['amount' => $amount]), 'sold_by_weight' => false];
        }

        return $catalog;
    }

    /**
     * Step 2: choose the pickup time and, when the laundry brings the order back, the delivery time.
     *
     * @param  array<string, mixed>  $d  LaundryThemeController::schedule's data
     * @return array<string, mixed> Checkout
     */
    public function schedule(array $d): array
    {
        $mode = (string) $d['mode'];
        $s = $d['schedule'];
        $quote = $this->orders->quote($d['quote'], ___('Total'));
        $names = implode(', ', array_column($quote['lines'], 'name'));

        $out = [
            'step_label' => ___('Step :current of :total', ['current' => '2', 'total' => '3']),
            'summary_rows' => [['icon' => 'shopping-bag', 'label' => $names, 'sub' => $this->weights($d['quote'])]],
            'slots' => $this->picker($s['days'], $s['groups'], $s['date'], $mode === 'delivery' ? ___('Pickup time') : ___('Drop-off time'), $s['freeCount'], null, $d['pickup'] ?? null),
        ];
        if ($mode === 'delivery') {
            $out['return_slots'] = $this->picker($s['returnDays'], $s['returnGroups'], (string) $s['returnDate'], ___('Delivery time'), $s['returnFreeCount'], ___('Delivery is at least :hours hours after pickup.', ['hours' => (string) $s['turnaround']]), $d['return'] ?? null);
        }
        $out['payment_methods'] = [];
        $out['quote'] = $quote;
        $out['place_label'] = ___('Continue');
        $out['form'] = [
            'action' => route('laundry.book.hold', ['slug' => $this->store->slug], false),
            'refresh' => route('laundry.book.schedule', ['slug' => $this->store->slug], false),
            'back' => route('publicView', ['slug' => $this->store->slug], false),
            'hidden' => array_values(array_filter([
                ['name' => 'type', 'value' => $mode],
                ($d['pickup'] ?? null) !== null ? ['name' => 'pickup_keep', 'value' => (string) $d['pickup']] : null,
                ($d['return'] ?? null) !== null ? ['name' => 'return_keep', 'value' => (string) $d['return']] : null,
            ])),
        ];
        $this->messages($out, $d);

        return $out;
    }

    /**
     * Step 3: your details, how you pay, the summary that sums to the total.
     *
     * @param  array<string, mixed>  $d  LaundryThemeController::confirm's data
     * @return array<string, mixed> Checkout
     */
    public function details(array $d, ?string $customerName, ?string $customerPhone): array
    {
        $mode = (string) $d['mode'];
        $delivery = $mode === 'delivery';
        $quote = $this->orders->quote($d['quote'], ___('Total'));

        $rows = [['icon' => 'calendar', 'label' => $this->when($d['pickupAt']), 'sub' => $delivery ? ___('We collect from you') : ___('You drop off')]];
        if ($d['returnAt'] !== null) {
            $rows[] = ['icon' => 'truck', 'label' => $this->when($d['returnAt']), 'sub' => ___('We bring it back')];
        }
        $rows[] = ['icon' => 'shopping-bag', 'label' => implode(', ', array_column($quote['lines'], 'name')), 'sub' => $this->weights($d['quote'])];

        $fields = [
            ['id' => 'customer_name', 'label' => ___('Name'), 'value' => (string) $customerName, 'type' => 'text', 'required' => true],
            ['id' => 'phone_number', 'label' => ___('Mobile (for booking updates)'), 'value' => (string) $customerPhone, 'type' => 'tel', 'dir' => 'ltr', 'required' => true],
        ];
        if ($delivery) {
            $fields[] = ['id' => 'address', 'label' => ___('Address for pickup and delivery'), 'type' => 'text', 'required' => true];
        }
        $fields = array_map(fn (array $f) => array_filter($f, fn ($v) => $v !== ''), $fields);

        $out = [
            'step_label' => ___('Step :current of :total', ['current' => '3', 'total' => '3']),
            'summary_rows' => $rows,
            'fields' => $fields,
            'payment_methods' => [['id' => 'cod', 'label' => $delivery ? ___('Cash on delivery') : ___('Pay at the counter'), 'selected' => true, 'kind' => 'venue']],
            'hold_note' => ___('Held for you until :time.', ['time' => (string) $d['expiresAt']]),
            'quote' => $quote,
            'place_label' => ___('Confirm booking'),
            'form' => [
                'action' => route('laundry.book.place', ['slug' => $this->store->slug], false),
                'back' => (string) $d['back'],
                'hidden' => [
                    ['name' => 'type', 'value' => $mode],
                    ['name' => 'hold', 'value' => (string) $d['hold']->public_id],
                    ['name' => 'client_order_id', 'value' => (string) $d['clientKey']],
                ],
            ],
        ];
        if ($this->hasWeighed($d['quote'])) {
            $out['policy_note'] = ___('The final price is set when your laundry is weighed.');
        }
        $this->messages($out, $d);

        return $out;
    }

    // ---- pieces -----------------------------------------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $days
     * @param  array<string, list<array{id: string, label: string, free: bool}>>  $groups
     * @return array<string, mixed> SlotPicker
     */
    private function picker(array $days, array $groups, string $date, string $title, int $freeCount, ?string $timezoneNote = null, ?string $selected = null): array
    {
        $rows = [];
        foreach ($days as $day) {
            $rows[] = ['id' => $day['date'], 'label' => (string) explode(' ', (string) $day['label'])[0], 'sub' => (string) (explode(' ', (string) $day['label'])[1] ?? ''), 'state' => $day['date'] === $date ? 'selected' : ($day['closed'] ? 'closed' : 'available')];
        }
        $out = [
            'title' => $title,
            'days' => $rows,
            'groups' => array_map(fn (string $label, array $slots) => [
                'label' => $label,
                'slots' => array_map(fn (array $s) => ['id' => $s['id'], 'label' => $s['label'], 'state' => ! $s['free'] ? 'full' : ($s['id'] === $selected ? 'selected' : 'available')], $slots),
            ], array_keys($groups), array_values($groups)),
        ];
        if ($freeCount === 0) {
            $out['note'] = ___('No free times on this day. Try another day.');
        } elseif ($timezoneNote !== null) {
            $out['timezone_note'] = $timezoneNote;
        }

        return $out;
    }

    /** "2.5 kg · 3 items" — what the order holds, in the shopper's words. */
    private function weights(StorefrontQuote $q): string
    {
        $kg = 0.0;
        $pieces = 0;
        foreach ($q->order->items as $item) {
            if ($item->weight_kg !== null) {
                $kg += (float) $item->weight_kg;
            } else {
                $pieces += (int) $item->quantity;
            }
        }
        $parts = [];
        if ($kg > 0) {
            $parts[] = ___(':kg kg', ['kg' => rtrim(rtrim(number_format($kg, 3, '.', ''), '0'), '.')]);
        }
        if ($pieces > 0) {
            $parts[] = ___(':count pieces', ['count' => (string) $pieces]);
        }

        return implode(' · ', $parts);
    }

    private function hasWeighed(StorefrontQuote $q): bool
    {
        return $q->order->items->contains(fn ($i) => $i->weight_kg !== null);
    }

    /** "Sun 4 Oct · 4:30 pm" in the store's time. */
    private function when(\DateTimeInterface $at): string
    {
        $c = CarbonImmutable::instance($at)->locale(app()->getLocale());

        return $c->translatedFormat('D j M').' · '.$c->translatedFormat('g:i A');
    }

    /**
     * @param  array<string, mixed>  $out
     * @param  array<string, mixed>  $d
     */
    private function messages(array &$out, array $d): void
    {
        $rows = [];
        foreach ((array) ($d['messages'] ?? []) as $m) {
            $rows[] = ['kind' => $m['kind'] === 'success' ? 'success' : 'error', 'text' => (string) $m['text']];
        }
        if ($rows !== []) {
            $out['messages'] = $rows;
        }
    }
}
