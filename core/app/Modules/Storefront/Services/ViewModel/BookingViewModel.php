<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\ViewModel;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Modules\Bookings\Contracts\StorefrontBookings;

/**
 * P3-11 — the `book` theme's view model for a store that sells its services through the booking engine (Bookings).
 *
 * The same blocks as any view model, from the engine instead of the menu: the service catalogue (a bookable service
 * is an item card with a duration), the staff, the next free time, the booking quote (services + tax, with the
 * deposit and what is due later), and the pages of the flow — choose a time, confirm, pay the deposit, the booking
 * page. Money is server-formatted (MoneyFormat); ids are public ids; times are the store's, with its zone named.
 *
 * What a step computed (the Bookings controller's data) arrives as plain values and models in `$data`; this class
 * only words and shapes it. Never reads a request.
 */
final class BookingViewModel
{
    private const ICON_SERVICE = 'scissors';

    /** @var array<string, mixed>|null */
    private ?array $rulesMemo = null;

    public function __construct(
        private readonly Store $store,
        private readonly MoneyFormat $money,
        private readonly StorefrontBookings $bookings,
        private readonly string $locale,
    ) {}

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->rulesMemo ??= $this->bookings->rules($this->store);
    }

    // ---- home -------------------------------------------------------------------------------------------------

    /**
     * Every active service (venue and at-home) as item cards in one category; the card says where it can be done.
     *
     * @return array{categories: list<array<string, mixed>>, items: array<string, array<string, mixed>>, total: int}
     */
    public function catalog(): array
    {
        $services = [];
        foreach (['venue', 'at_home'] as $location) {
            foreach ($this->bookings->services($this->store, $location) as $s) {
                $services[$s['id']] ??= $s;
            }
        }
        $services = array_values($services);

        $cards = [];
        foreach ($services as $s) {
            $cards[$s['id']] = $this->card($s);
        }
        $ids = array_keys($cards);

        return [
            'categories' => $ids === [] ? [] : [['id' => 'services', 'name' => ___('Services'), 'item_ids' => $ids, 'count' => count($ids)]],
            'items' => $cards,
            'total' => count($cards),
        ];
    }

    /**
     * @param  array<string, mixed>  $s
     * @return array<string, mixed> ItemCard
     */
    private function card(array $s): array
    {
        $card = [
            'id' => $s['id'],
            'name' => $s['name'],
            'url' => $this->itemUrl($s['id']),
        ];
        if ($s['description'] !== '') {
            $card['short'] = trim(mb_strimwidth(strip_tags($s['description']), 0, 140, '…'));
        }
        $card['price'] = $this->money->money($s['price_minor']);
        $card['badges'] = [];
        $card['sold_out'] = false;
        $card['has_required_choices'] = false;
        $card['duration_min'] = $s['duration_min'];
        $card['meta_label'] = $this->duration($s['duration_min']).' · '.$this->money->format($s['price_minor']);
        $card['venue'] = $s['venue'];
        $card['at_home'] = $s['at_home'];

        return $card;
    }

    private function itemUrl(string $id): string
    {
        return route('publicView', ['slug' => $this->store->slug], false).'?item='.$id;
    }

    /** @return array<string, mixed>|null ItemDetail of one service, null when it is not this store's */
    public function item(string $publicId): ?array
    {
        $found = null;
        foreach (['venue', 'at_home'] as $location) {
            foreach ($this->bookings->services($this->store, $location) as $s) {
                if ($s['id'] === $publicId) {
                    $found = $s;
                    break 2;
                }
            }
        }
        if ($found === null) {
            return null;
        }
        $rules = $this->rules();
        $detail = ['id' => $found['id'], 'name' => $found['name']];
        if ($found['description'] !== '') {
            $detail['description'] = trim(strip_tags($found['description']));
        }
        $detail['price'] = $this->money->money($found['price_minor']);
        $detail['badges'] = [];
        $detail['base_label'] = $this->duration($found['duration_min']).' · '.$this->money->format($found['price_minor']);
        $detail['option_groups'] = [];
        if ($found['deposit_minor'] > 0) {
            $detail['deposit'] = $this->money->money($found['deposit_minor']);
        }
        $detail['policy_note'] = $this->policyLine((int) $rules['cancel_window_hours'], $found['deposit_minor'] > 0, (bool) $rules['deposit_refunds']);
        $detail['add_label'] = ___('Add to booking · :duration · :amount', ['duration' => $this->duration($found['duration_min']), 'amount' => $this->money->format($found['price_minor'])]);

        return $detail;
    }

    /**
     * @return array<string, mixed> the `context` block of a booking store
     */
    public function context(?string $selected): array
    {
        $rules = $this->rules();
        $types = [['key' => 'appointment', 'label' => ___('At the venue'), 'needs_address' => false, 'needs_slot' => true]];
        if ($rules['at_home'] && $this->hasAtHomeServices()) {
            $types[] = ['key' => 'at_home', 'label' => ___('At home'), 'needs_address' => true, 'needs_slot' => true];
        }
        $keys = array_column($types, 'key');
        $out = [
            'order_types' => $types,
            'selected_order_type' => in_array($selected, $keys, true) ? $selected : 'appointment',
            'table' => null,
            'room' => null,
            'schedule_ahead' => true,
        ];

        $next = $this->bookings->nextFree($this->store, 'venue');
        if ($next !== null) {
            $at = CarbonImmutable::parse($next)->setTimezone((string) $rules['timezone']);
            $out['next_free_slot'] = ['label' => $this->when($at), 'at' => $at->toIso8601String()];
        }
        $staff = $this->staffBlock();
        if ($staff !== []) {
            $out['staff'] = $staff;
        }

        return $out;
    }

    private function hasAtHomeServices(): bool
    {
        return $this->bookings->services($this->store, 'at_home') !== [];
    }

    /** @return list<array<string, mixed>> Staff[] */
    private function staffBlock(): array
    {
        $out = [];
        foreach ($this->bookings->staff($this->store) as $m) {
            $row = ['id' => $m['id'], 'name' => $m['name']];
            if ($m['role'] !== '') {
                $row['role'] = $m['role'];
            }
            if ($m['photo'] !== '') {
                $row['photo'] = ['src' => media_url('storage/store/logo/', $m['photo'], (int) $this->store->id), 'alt' => $m['name']];
            }
            $row['service_ids'] = $m['service_ids'];
            $out[] = $row;
        }

        return $out;
    }

    // ---- quote ------------------------------------------------------------------------------------------------

    /**
     * The booking quote of a selection (public ids): the lines, subtotal, tax, total, and what is due now / later.
     *
     * @param  list<string>  $serviceIds
     * @return array<string, mixed>|null Quote
     */
    public function quote(array $serviceIds, string $location, bool $withDeposit = false): ?array
    {
        $q = $this->bookings->quote($this->store, $serviceIds, $location);
        if ($q === null) {
            return null;
        }

        return $this->quoteFrom($q, $withDeposit);
    }

    /**
     * @param  array<string, mixed>  $q  StorefrontBookings::quote()
     * @return array<string, mixed> Quote
     */
    public function quoteFrom(array $q, bool $withDeposit, ?string $depositLabel = null, ?string $dueLabel = null): array
    {
        $lines = [];
        foreach ($q['lines'] as $i => $line) {
            $lines[] = [
                'id' => 'ln_'.($i + 1),
                'item_id' => (string) ($line['id'] ?? $line['service_id'] ?? $i),
                'name' => $line['name'],
                'qty' => 1,
                'line_total' => $this->money->money($line['price_minor']),
                'choices_label' => $this->duration($line['duration_min']),
            ];
        }
        $rows = [['key' => 'subtotal', 'label' => $q['tax_minor'] > 0 ? ___('Subtotal') : ___('Total'), 'amount' => $this->money->money($q['subtotal_minor']), 'kind' => 'subtotal']];
        if ($q['tax_minor'] > 0) {
            $rows[] = ['key' => 'tax', 'label' => (string) $this->rules()['tax_label'], 'amount' => $this->money->money($q['tax_minor']), 'kind' => 'tax'];
            $rows[] = ['key' => 'total', 'label' => ___('Total'), 'amount' => $this->money->money($q['total_minor']), 'kind' => 'total'];
        }
        if ($withDeposit && $q['deposit_minor'] > 0) {
            $rows[] = ['key' => 'deposit', 'label' => $depositLabel ?? ___('Due now (deposit)'), 'amount' => $this->money->money($q['deposit_minor']), 'kind' => 'deposit', 'muted' => true, 'informational' => true];
            $rows[] = ['key' => 'due_later', 'label' => $dueLabel ?? ___('Due at the appointment'), 'amount' => $this->money->money($q['due_later_minor']), 'kind' => 'due_later', 'muted' => true, 'informational' => true];
        }

        return [
            'lines' => $lines,
            'rows' => $rows,
            'total' => $this->money->money($q['total_minor']),
            'total_label' => ___('Total'),
        ];
    }

    /**
     * @param  list<string>  $serviceIds
     * @return array<string, mixed>|null the cart page's Quote (with its notes and the "choose a time" button)
     */
    public function cart(array $serviceIds, string $location): ?array
    {
        $q = $this->bookings->quote($this->store, $serviceIds, $location);
        if ($q === null) {
            return null;
        }
        $quote = $this->quoteFrom($q, false);
        $rules = $this->rules();
        $quote['notes'] = array_values(array_filter([
            $this->policyLine((int) $rules['cancel_window_hours'], false, (bool) $rules['deposit_refunds']),
            trim((string) $rules['policy_note']),
        ]));
        $quote['cta_label'] = ___('Choose a time');

        return $quote;
    }

    // ---- pages ------------------------------------------------------------------------------------------------

    /**
     * Choose a time / choose a new time (the step-2 page and the reschedule page).
     *
     * @param  array<string, mixed>  $d  BookingStorefrontController's `times` data
     * @return array<string, mixed> Checkout
     */
    public function times(array $d, bool $reschedule): array
    {
        $rules = $this->rules();
        $out = [];
        if (! $reschedule) {
            $out['step_label'] = ___('Step :current of :total', ['current' => '2', 'total' => '3']);
        }
        $own = $reschedule ? $d['reschedule'] : null;
        if ($own !== null) {
            $tz = (string) $rules['timezone'];
            $start = CarbonImmutable::instance($own->starts_at)->setTimezone($tz);
            $end = CarbonImmutable::instance($own->ends_at)->setTimezone($tz);
            $out['summary_rows'] = [
                ['icon' => 'calendar', 'label' => $this->when($start).'–'.$this->clock($end), 'sub' => $this->duration((int) $own->duration_min).' · '.$this->zoneName($tz)],
                ['icon' => self::ICON_SERVICE, 'label' => (string) $d['services']->pluck('name')->implode(', ')],
            ];
            $out['policy_note'] = ___('You can reschedule until :when.', ['when' => CarbonImmutable::instance($own->cancel_deadline_at)->setTimezone($tz)->locale($this->locale)->translatedFormat('D j M').', '.$this->clock(CarbonImmutable::instance($own->cancel_deadline_at)->setTimezone($tz))]);
        } else {
            $out['summary_rows'] = [['icon' => self::ICON_SERVICE, 'label' => (string) $d['services']->pluck('name')->implode(', '), 'sub' => $this->duration((int) $d['duration']).' · '.$this->zoneName((string) $rules['timezone'])]];
        }

        $staff = [];
        if ($d['staffChoice']) {
            $staff[] = ['id' => 'any', 'name' => ___('Any staff'), 'role' => ___('Earliest')];
            foreach ($d['staffList'] as $member) {
                $row = ['id' => (string) $member->public_id, 'name' => (string) $member->name];
                if (trim((string) $member->role) !== '') {
                    $row['role'] = trim((string) $member->role);
                }
                if (trim((string) $member->photo_url) !== '') {
                    $row['photo'] = ['src' => media_url('storage/store/logo/', trim((string) $member->photo_url), (int) $this->store->id), 'alt' => (string) $member->name];
                }
                $staff[] = $row;
            }
            $out['staff'] = $staff;
            $selected = 'any';
            foreach ($d['staffList'] as $member) {
                if ($d['staffId'] !== null && (int) $member->id === (int) $d['staffId']) {
                    $selected = (string) $member->public_id;
                }
            }
            $out['selected_staff'] = $selected;
        }

        $out['slots'] = $this->slotPicker($d);
        $out['payment_methods'] = [];
        if ($own !== null) {
            $quote = $this->quoteFrom($this->bookingQuote($own), true, ___('Deposit paid (carried over)'), ___('Due at the appointment'));
        } else {
            $quote = $this->quote($d['services']->pluck('public_id')->map(fn ($id) => (string) $id)->all(), (string) $d['location']);
        }
        $out['quote'] = $quote ?? ['lines' => [], 'rows' => [], 'total' => $this->money->money(0)];
        $out['place_label'] = $reschedule ? ___('Confirm new time') : ___('Continue');
        $slug = (string) $this->store->slug;
        $hidden = [];
        if (! $reschedule) {
            foreach ($d['services'] as $service) {
                $hidden[] = ['name' => 'services[]', 'value' => (string) $service->public_id];
            }
            $hidden[] = ['name' => 'location', 'value' => (string) $d['location']];
            foreach (['lat', 'lng'] as $axis) {
                if (($d['coords'][$axis] ?? null) !== null) {
                    $hidden[] = ['name' => $axis, 'value' => (string) $d['coords'][$axis]];
                }
            }
        }
        $out['form'] = [
            'action' => (string) $d['action'],
            'refresh' => $reschedule ? route('bookings.rescheduleTimes', ['slug' => $slug, 'token' => (string) $d['token']], false) : route('bookings.times', ['slug' => $slug], false),
            'back' => $reschedule ? route('bookings.manage', ['slug' => $slug, 'token' => (string) $d['token']], false) : route('publicView', ['slug' => $slug], false),
            'hidden' => $hidden,
        ];
        $this->messages($out, $d);

        return $out;
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array<string, mixed> SlotPicker
     */
    private function slotPicker(array $d): array
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', (string) $d['date'], (string) $this->rules()['timezone']);
        $days = [];
        foreach ($d['days'] as $day) {
            $at = CarbonImmutable::createFromFormat('!Y-m-d', $day['date'], (string) $this->rules()['timezone']);
            $state = match (true) {
                $day['date'] === $d['date'] => 'selected',
                $day['closed'] => 'closed',
                default => 'available',
            };
            $days[] = ['id' => $day['date'], 'label' => $at->locale($this->locale)->translatedFormat('D'), 'sub' => $at->format('j'), 'state' => $state];
        }

        $groups = [];
        foreach ($d['groups'] as $label => $slots) {
            $rows = [];
            foreach ($slots as $slot) {
                $rows[] = [
                    'id' => (string) $slot['ts'],
                    'label' => $this->clock(CarbonImmutable::createFromTimestampUTC((int) $slot['ts'])->setTimezone((string) $this->rules()['timezone'])),
                    'sub' => $this->range($slot['range']),
                    'state' => $slot['free'] ? 'available' : 'full',
                ];
            }
            $groups[] = ['label' => (string) $label, 'slots' => $rows];
        }

        $out = [
            'title' => $date->locale($this->locale)->translatedFormat('F Y'),
            'days' => $days,
            'groups' => $groups,
            'timezone_note' => $this->zoneNote((string) $this->rules()['timezone']),
        ];
        if ($d['freeCount'] === 0) {
            $out['note'] = ___('No free times on this day. Try another day or any staff member.');
        } elseif ($d['freeCount'] <= 3) {
            $out['note'] = ___('Only :count times left on this day.', ['count' => (string) $d['freeCount']]);
        }

        return $out;
    }

    /**
     * The confirm page (step 3) of a live hold.
     *
     * @param  array<string, mixed>  $d  BookingStorefrontController's `details` data
     * @return array<string, mixed> Checkout
     */
    public function details(array $d, ?string $customerName, ?string $customerPhone): array
    {
        $hold = $d['hold'];
        $rules = $this->rules();
        $tz = (string) $rules['timezone'];
        $start = CarbonImmutable::instance($hold->starts_at)->setTimezone($tz);
        $q = $d['quote'];
        $deposit = (int) $q['deposit_minor'];

        $rows = [
            ['icon' => 'calendar', 'label' => $start->locale($this->locale)->translatedFormat('D j M'), 'sub' => $d['summary']['time'].' · '.$d['summary']['duration'].' · '.$this->zoneName($tz)],
            ['icon' => self::ICON_SERVICE, 'label' => $d['summary']['services']],
        ];
        if ($d['summary']['staff'] !== '') {
            $rows[] = ['icon' => 'user', 'label' => ___('With :name', ['name' => $d['summary']['staff']])];
        }
        if ($d['atHome']) {
            $rows[] = ['icon' => 'map-pin', 'label' => ___('At your address')];
        } elseif (trim((string) $this->store->address) !== '') {
            $rows[] = ['icon' => 'map-pin', 'label' => (string) $this->store->title, 'sub' => trim((string) $this->store->address)];
        }

        $fields = [
            ['id' => 'name', 'label' => ___('Name'), 'value' => (string) $customerName, 'type' => 'text', 'required' => true],
            ['id' => 'phone', 'label' => ___('Mobile (for booking updates)'), 'value' => (string) $customerPhone, 'type' => 'tel', 'dir' => 'ltr', 'required' => true],
            ['id' => 'email', 'label' => ___('Email (optional)'), 'type' => 'email', 'dir' => 'ltr', 'required' => false],
        ];
        if ($d['atHome']) {
            $fields[] = ['id' => 'address', 'label' => ___('Address for the visit'), 'type' => 'text', 'required' => true];
        }
        $fields[] = ['id' => 'note', 'label' => ___('Note for the business (optional)'), 'type' => 'textarea', 'required' => false];
        $fields = array_map(fn (array $f) => array_filter($f, fn ($v) => $v !== ''), $fields);

        $payments = $deposit > 0
            ? [['id' => 'deposit', 'label' => ___('Pay a deposit now'), 'sub' => ___(':amount at the appointment', ['amount' => $this->money->format((int) $q['due_later_minor'])]), 'amount' => $this->money->money($deposit), 'selected' => true, 'kind' => 'deposit']]
            : [['id' => 'venue', 'label' => $d['atHome'] ? ___('Pay at the visit') : ___('Pay at the appointment'), 'selected' => true, 'kind' => 'venue']];

        $out = [
            'step_label' => ___('Step :current of :total', ['current' => '3', 'total' => '3']),
            'summary_rows' => $rows,
            'fields' => $fields,
            'payment_methods' => $payments,
            'hold_note' => ___('Held for you until :time.', ['time' => (string) $d['expiresAt']]),
            'policy_note' => (string) $d['policy'],
            'quote' => $this->quoteFrom($q, true),
            'place_label' => $deposit > 0 ? ___('Confirm and pay :amount', ['amount' => $this->money->format($deposit)]) : ___('Confirm booking'),
        ];
        if (trim((string) $rules['policy_note']) !== '') {
            $out['policy_note'] .= ' '.trim((string) $rules['policy_note']);
        }
        $slug = (string) $this->store->slug;
        $back = route('bookings.times', ['slug' => $slug], false).'?'.http_build_query(['services' => $d['services']->pluck('public_id')->map(fn ($v) => (string) $v)->all(), 'location' => (string) $hold->location]);
        $hidden = [['name' => 'hold', 'value' => (string) $hold->public_id], ['name' => 'client_key', 'value' => (string) $d['clientKey']]];
        foreach (['lat', 'lng'] as $axis) {
            if (($d['coords'][$axis] ?? null) !== null && $d['coords'][$axis] !== '') {
                $hidden[] = ['name' => $axis, 'value' => (string) $d['coords'][$axis]];
            }
        }
        $out['form'] = ['action' => route('bookings.book', ['slug' => $slug], false), 'back' => $back, 'hidden' => $hidden];
        $this->messages($out, $d);

        return $out;
    }

    /**
     * The deposit payment page (the booking waits for its deposit): the gateways, the amount, the time left.
     *
     * @param  array<string, mixed>  $d  BookingStorefrontController's `pay` data
     * @return array<string, mixed> Checkout
     */
    public function pay(array $d): array
    {
        $booking = $d['booking'];
        $payments = [];
        foreach ($d['gateways']->values() as $i => $gateway) {
            $payments[] = ['id' => (string) $gateway->id, 'label' => (string) $gateway->payment_title, 'icon' => 'credit-card'] + ($i === 0 ? ['selected' => true] : []);
        }
        $q = $this->bookingQuote($booking);

        $out = [
            'summary_rows' => $this->bookingRows($booking, (string) $this->rules()['timezone']),
            'payment_methods' => $payments,
            'quote' => $this->quoteFrom($q, true),
            'place_label' => ___('Pay :amount', ['amount' => $this->money->format((int) $booking->deposit_minor)]),
        ];
        if (! empty($d['expiresAt'])) {
            $out['hold_note'] = ___('Pay by :time to keep this time.', ['time' => (string) $d['expiresAt']]);
        }
        $slug = (string) $this->store->slug;
        $out['form'] = [
            'action' => route('bookings.charge', ['slug' => $slug, 'token' => (string) $d['token']], false),
            'back' => route('bookings.manage', ['slug' => $slug, 'token' => (string) $d['token']], false),
            'hidden' => [],
        ];
        $this->messages($out, $d);

        return $out;
    }

    /**
     * The booking page (the "track" page of a booking): ticket, timeline, location, receipt, manage actions.
     *
     * @param  array<string, mixed>  $d  BookingStorefrontController's `manage` data
     * @return array<string, mixed> Order
     */
    public function manage(array $d): array
    {
        $booking = $d['booking'];
        $tz = (string) $this->rules()['timezone'];
        $start = CarbonImmutable::instance($booking->starts_at)->setTimezone($tz);
        $end = CarbonImmutable::instance($booking->ends_at)->setTimezone($tz);
        $status = (string) $booking->status;

        $out = [
            'number' => (string) $booking->number,
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'eta_label' => $this->when($start),
            'heading' => in_array($status, ['confirmed', 'completed'], true) ? ___("You're booked, :name", ['name' => $this->firstName((string) $booking->customer_name)]) : $this->statusLabel($status),
            'ticket' => [
                'title' => $start->locale($this->locale)->translatedFormat('l'),
                'big' => $start->locale($this->locale)->translatedFormat('j M').' · '.$this->clock($start),
                'sub' => $this->duration((int) $booking->duration_min).' · '.___('ends :time', ['time' => $this->clock($end)]),
            ],
            'steps' => $this->steps($booking, $start),
        ];

        $location = $this->bookingLocation($booking);
        if ($location !== null) {
            $out['location'] = $location;
        }
        $out['quote'] = $this->quoteFrom($this->bookingQuote($booking), true,
            $booking->deposit_status === 'paid' ? ___('Paid · deposit') : ___('Due now (deposit)'),
            $booking->deposit_status === 'paid' || $booking->deposit_minor <= 0 ? ___('Due at the appointment') : ___('Due at the appointment'));
        if ($booking->deposit_status === 'paid') {
            $out['paid_label'] = ___('Paid · deposit');
        }

        $actions = [
            ['label' => ___('Add to calendar'), 'url' => route('bookings.calendar', ['slug' => $this->store->slug, 'token' => $d['token']], false), 'kind' => 'primary', 'icon' => 'calendar-plus'],
        ];
        if ($booking->status === 'pending_payment') {
            array_unshift($actions, ['label' => ___('Pay the deposit'), 'url' => route('bookings.pay', ['slug' => $this->store->slug, 'token' => $d['token']], false), 'kind' => 'primary', 'icon' => 'credit-card']);
        }
        if ($d['mapsUrl'] !== '' && $booking->status !== 'cancelled') {
            $actions[] = ['label' => ___('Directions'), 'url' => (string) $d['mapsUrl'], 'kind' => 'secondary', 'icon' => 'navigation'];
        }
        if ($d['canChange']) {
            $actions[] = ['label' => ___('Reschedule'), 'url' => route('bookings.rescheduleTimes', ['slug' => $this->store->slug, 'token' => $d['token']], false), 'kind' => 'secondary', 'icon' => 'calendar-clock'];
        }
        if ($d['canCancel']) {
            $actions[] = ['label' => ___('Cancel booking'), 'url' => route('bookings.cancel', ['slug' => $this->store->slug, 'token' => $d['token']], false), 'kind' => 'danger', 'icon' => 'x', 'method' => 'post'];
        }
        $out['actions'] = $actions;
        $out['policy_note'] = (string) $d['policy'];
        $this->messages($out, $d);

        return $out;
    }

    /**
     * What the last step answered (a refused step's errors, a done step's notice), from the messages the controller carries.
     *
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

    // ---- pieces -----------------------------------------------------------------------------------------------

    /**
     * What a stored booking charged, in the shape of a quote.
     *
     * @return array<string, mixed>
     */
    private function bookingQuote(object $booking): array
    {
        $booking->loadMissing('lines');
        $subtotal = (int) $booking->total_minor - (int) $booking->tax_minor;

        return [
            'currency' => (string) $booking->currency,
            'subtotal_minor' => $subtotal,
            'tax_minor' => (int) $booking->tax_minor,
            'total_minor' => (int) $booking->total_minor,
            'deposit_minor' => (int) $booking->deposit_minor,
            'due_later_minor' => (int) $booking->total_minor - (int) $booking->deposit_minor,
            'duration_min' => (int) $booking->duration_min,
            'lines' => $booking->lines->map(fn ($l) => [
                'id' => 'line-'.$l->id, 'name' => (string) $l->name, 'duration_min' => (int) $l->duration_min,
                'price_minor' => (int) $l->price_minor, 'deposit_minor' => (int) $l->deposit_minor,
            ])->values()->all(),
        ];
    }

    /** @return list<array<string, mixed>> InfoRow[] */
    private function bookingRows(object $booking, string $tz): array
    {
        $start = CarbonImmutable::instance($booking->starts_at)->setTimezone($tz);
        $booking->loadMissing('lines');

        return [
            ['icon' => 'calendar', 'label' => $this->when($start), 'sub' => $this->duration((int) $booking->duration_min).' · '.$this->zoneName($tz)],
            ['icon' => self::ICON_SERVICE, 'label' => (string) $booking->lines->pluck('name')->implode(', ')],
        ];
    }

    /** @return array<string, mixed>|null InfoRow */
    private function bookingLocation(object $booking): ?array
    {
        if ($booking->location === 'at_home') {
            return trim((string) $booking->address) !== '' ? ['icon' => 'map-pin', 'label' => ___('At your address'), 'sub' => trim((string) $booking->address)] : null;
        }
        $address = trim((string) $this->store->address);

        return $address !== '' ? ['icon' => 'map-pin', 'label' => (string) $this->store->title, 'sub' => $address] : null;
    }

    /** @return list<array{label: string, sub?: string, state: string}> */
    private function steps(object $booking, CarbonImmutable $start): array
    {
        $tz = (string) $this->rules()['timezone'];
        $status = (string) $booking->status;
        $created = CarbonImmutable::instance($booking->created_at)->setTimezone($tz);
        $steps = [['label' => ___('Booked'), 'sub' => $created->locale($this->locale)->translatedFormat('D j M').', '.$this->clock($created), 'state' => 'done']];
        if (in_array($status, ['cancelled'], true)) {
            $steps[] = ['label' => ___('Cancelled'), 'sub' => $booking->cancelled_at ? CarbonImmutable::instance($booking->cancelled_at)->setTimezone($tz)->locale($this->locale)->translatedFormat('D j M').', '.$this->clock(CarbonImmutable::instance($booking->cancelled_at)->setTimezone($tz)) : '', 'state' => 'current'];

            return array_map(fn (array $s) => array_filter($s, fn ($v) => $v !== ''), $steps);
        }
        $done = in_array($status, ['completed', 'no_show'], true);
        $reminder = $start->subHours(24);
        $steps[] = ['label' => ___('Reminder'), 'sub' => $reminder->locale($this->locale)->translatedFormat('D j M').', '.$this->clock($reminder).' '.___('(24 h before)'), 'state' => $done || $reminder->isPast() ? 'done' : 'current'];
        $steps[] = ['label' => ___('Check in'), 'sub' => ___('Show this screen or say :number', ['number' => (string) $booking->number]), 'state' => $done ? 'done' : ($reminder->isPast() ? 'current' : 'todo')];
        $steps[] = ['label' => $status === 'no_show' ? ___('Missed') : ___('Done'), 'state' => $done ? 'done' : 'todo'];

        return $steps;
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'confirmed' => ___('Confirmed'),
            'pending_payment' => ___('Waiting for the deposit'),
            'pending_approval' => ___('Pending approval'),
            'cancelled' => ___('Cancelled'),
            'completed' => ___('Done'),
            'no_show' => ___('Missed'),
            default => ___('Confirmed'),
        };
    }

    private function policyLine(int $hours, bool $hasDeposit, bool $refunds): string
    {
        $hours = max(0, $hours);
        if (! $hasDeposit) {
            return ___('Free cancellation or changes up to :hours h before.', ['hours' => (string) $hours]);
        }

        return $refunds
            ? ___('Free cancellation or changes up to :hours h before. After that the deposit is kept.', ['hours' => (string) $hours])
            : ___('Deposits are not refunded. You can reschedule up to :hours h before.', ['hours' => (string) $hours]);
    }

    private function firstName(string $name): string
    {
        $name = trim($name);

        return $name === '' ? '' : (string) preg_split('/\s+/u', $name)[0];
    }

    /** "45 min", "2 h", "2 h 30 min" (never "1.5 h"). */
    public function duration(int $minutes): string
    {
        $h = intdiv(max(0, $minutes), 60);
        $m = max(0, $minutes) % 60;
        if ($h === 0) {
            return ___(':count min', ['count' => $m]);
        }

        return $m === 0 ? ___(':count h', ['count' => $h]) : ___(':hours h :minutes min', ['hours' => $h, 'minutes' => $m]);
    }

    /** "Sun 4 Oct · 4:30 pm" (the store's time, in the render language). */
    private function when(CarbonImmutable $at): string
    {
        return $at->locale($this->locale)->translatedFormat('D j M').' · '.$this->clock($at);
    }

    private function clock(CarbonImmutable $at): string
    {
        return $at->locale($this->locale)->translatedFormat('g:i A');
    }

    /** "16:30–17:45" -> "4:30–5:45 pm" is the server's job; the slot's own range keeps the store's 24 h clock text. */
    private function range(string $range): string
    {
        return $range;
    }

    private function zoneName(string $tz): string
    {
        return str_replace('_', ' ', (string) Str::afterLast($tz, '/'));
    }

    private function zoneNote(string $tz): string
    {
        $offset = CarbonImmutable::now($tz)->format('P');

        return ___('Times are in :zone time (GMT:offset).', ['zone' => $this->zoneName($tz), 'offset' => $offset]);
    }
}
