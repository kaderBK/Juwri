<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\Api;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Modules\Ordering\Contracts\DeliverySlotBook;
use Juwri\Modules\Ordering\Data\SlotException;
use Juwri\Modules\Ordering\Models\SlotHold;
use Juwri\Modules\Storefront\Services\ViewModel\MoneyFormat;

/**
 * P3-09 / P3-06b phase C: the slot payloads of the headless API (kit/api `DeliverySlot`, `DeliverySlotsResponse`,
 * `Hold`) — machine fields (ISO times with the store's offset, capacity) next to the View Model display objects
 * (`Slot`, `SlotPicker`, `Choice`), labels in the request language, money as Money.
 */
final class StorefrontSlotView
{
    public function __construct(private readonly DeliverySlotBook $slots) {}

    /**
     * @param  list<array{date: string, slots: list<array<string, mixed>>}>  $days
     * @param  array{fee_minor: int, eta_from: int, eta_to: int}|null  $express
     * @return array<string, mixed> DeliverySlotsResponse
     */
    public function response(StorefrontApiContext $ctx, string $orderType, array $days, ?array $express): array
    {
        return $this->build($ctx->store, $ctx->branch, $orderType, $days, $express);
    }

    /**
     * The same answer for any caller that knows the store and branch (the storefront's own pages: the checkout's slot picker
     * of the View Model, the web slots endpoint).
     *
     * @param  list<array{date: string, slots: list<array<string, mixed>>}>  $days
     * @param  array{fee_minor: int, eta_from: int, eta_to: int}|null  $express
     * @return array<string, mixed> DeliverySlotsResponse
     */
    public function build(Store $store, ?Branch $branch, string $orderType, array $days, ?array $express): array
    {
        $tz = $this->slots->timezone($store, $branch);
        $money = new MoneyFormat($store);
        $outDays = [];
        $pickerDays = [];
        $selected = null;
        foreach ($days as $day) {
            $slots = array_map(fn (array $slot) => $this->slotFor($store, $branch, $slot), $day['slots']);
            $hasRoom = array_filter($slots, fn (array $s) => $s['state'] !== 'full') !== [];
            $display = $this->dayDisplay($day['date'], $tz, $hasRoom);
            if ($selected === null && $hasRoom) {
                $selected = count($outDays);
                $display['state'] = 'selected';
            }
            $outDays[] = ['date' => $day['date'], 'display' => $display, 'slots' => $slots];
            $pickerDays[] = $display;
        }

        $body = ['timezone' => $tz, 'days' => $outDays];
        $choice = null;
        if ($express !== null) {
            $choice = [
                'id' => 'express',
                'label' => ___('Express · :from–:to min', ['from' => (string) $express['eta_from'], 'to' => (string) $express['eta_to']]),
                'amount' => $money->money($express['fee_minor']),
                'amount_label' => '+ '.$money->format($express['fee_minor']),
                'icon' => 'zap',
            ];
            $body['express'] = [
                'available' => true,
                'fee' => $money->money($express['fee_minor']),
                'eta_min_from' => $express['eta_from'],
                'eta_min_to' => $express['eta_to'],
                'display' => $choice,
            ];
        } else {
            $body['express'] = null;
        }

        $groups = [];
        if ($selected !== null) {
            $groups[] = ['label' => (string) $pickerDays[$selected]['label'], 'slots' => array_map(fn (array $s) => $s['display'], $outDays[$selected]['slots'])];
        }
        $picker = [
            'title' => $orderType === 'delivery' ? ___('Delivery time') : ___('Collection time'),
            'days' => $pickerDays,
            'groups' => $groups,
            'timezone_note' => ___('Times are in :city time (:offset)', ['city' => self::city($tz), 'offset' => self::offset($tz) === '+0' ? 'GMT' : 'GMT'.self::offset($tz)]),
        ];
        if ($choice !== null) {
            $picker['express'] = $choice;
        }
        $body['picker'] = $picker;

        return $body;
    }

    /**
     * @param  array<string, mixed>  $slot
     * @return array<string, mixed> DeliverySlot
     */
    public function slot(StorefrontApiContext $ctx, array $slot): array
    {
        return $this->slotFor($ctx->store, $ctx->branch, $slot);
    }

    /**
     * @param  array<string, mixed>  $slot
     * @return array<string, mixed> DeliverySlot
     */
    public function slotFor(Store $store, ?Branch $branch, array $slot): array
    {
        $tz = $this->slots->timezone($store, $branch);
        $money = new MoneyFormat($store);
        /** @var CarbonInterface $start */
        $start = $slot['starts_at'];
        /** @var CarbonInterface $end */
        $end = $slot['ends_at'];
        $label = CarbonImmutable::instance($start)->setTimezone($tz)->format('H:i').' – '.CarbonImmutable::instance($end)->setTimezone($tz)->format('H:i');
        $display = ['id' => (string) $slot['id'], 'label' => $label, 'state' => (string) $slot['state']];
        if ($slot['state'] === 'full') {
            $display['sub'] = ___('Full');
        } elseif ($slot['state'] === 'few') {
            $display['sub'] = ___('Few left');
        }

        return [
            'id' => (string) $slot['id'],
            'starts_at' => CarbonImmutable::instance($start)->setTimezone($tz)->toIso8601String(),
            'ends_at' => CarbonImmutable::instance($end)->setTimezone($tz)->toIso8601String(),
            'state' => (string) $slot['state'],
            'capacity_remaining' => $slot['remaining'] === null ? null : (int) $slot['remaining'],
            'fee' => $money->money((int) $slot['fee_minor']),
            'display' => $display,
        ];
    }

    /** @return array<string, mixed> Hold */
    public function hold(StorefrontApiContext $ctx, SlotHold $hold, string $token): array
    {
        $tz = $this->slots->timezone($ctx->store, $ctx->branch);

        return [
            'id' => (string) $hold->public_id,
            'token' => $token,
            'kind' => 'delivery_slot',
            'slot_id' => (string) $hold->slot_id,
            'expires_at' => CarbonImmutable::instance($hold->expires_at)->setTimezone($tz)->toIso8601String(),
            'ttl_seconds' => SlotHold::MINUTES * 60,
            'hold_note' => ___('We hold this time for 10 minutes.'),
        ];
    }

    /** The API error of a refused slot step (409 slot_full with `details.next_free_slot`, hold_expired, …). */
    public function refusal(StorefrontApiContext $ctx, SlotException $e): StorefrontApiException
    {
        if ($e->errorCode === 'validation_failed') {
            $field = (string) ($e->details['field'] ?? 'slot_id');

            return StorefrontApiException::validation([$field => [$e->getMessage()]]);
        }
        $details = null;
        if (isset($e->details['next_free_slot']) && is_array($e->details['next_free_slot'])) {
            $details = ['next_free_slot' => $this->slot($ctx, $e->details['next_free_slot'])];
        }

        return new StorefrontApiException($e->status, $e->errorCode, $e->getMessage(), null, $details);
    }

    /** "Riyadh" from "Asia/Riyadh". */
    private static function city(string $tz): string
    {
        $parts = explode('/', $tz);

        return str_replace('_', ' ', (string) end($parts));
    }

    /** "+3", "+5:30", "-4" */
    private static function offset(string $tz): string
    {
        $seconds = CarbonImmutable::now($tz)->getOffset();
        $sign = $seconds < 0 ? '-' : '+';
        $seconds = abs($seconds);
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);

        return $sign.$h.($m > 0 ? ':'.str_pad((string) $m, 2, '0', STR_PAD_LEFT) : '');
    }

    /** @return array{id: string, label: string, sub?: string, state: string} */
    private function dayDisplay(string $date, string $tz, bool $hasRoom): array
    {
        $day = CarbonImmutable::parse($date, $tz)->locale(app()->getLocale());
        $today = CarbonImmutable::now($tz)->startOfDay();
        $short = $day->translatedFormat('D j M');
        $out = ['id' => 'd_'.$day->format('Ymd')];
        if ($day->isSameDay($today)) {
            $out += ['label' => ___('Today'), 'sub' => $short];
        } elseif ($day->isSameDay($today->addDay())) {
            $out += ['label' => ___('Tomorrow'), 'sub' => $short];
        } else {
            $out['label'] = $short;
        }
        $out['state'] = $hasRoom ? 'available' : 'closed';

        return $out;
    }
}
