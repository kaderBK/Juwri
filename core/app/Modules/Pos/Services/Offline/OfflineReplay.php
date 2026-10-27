<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Services\Offline;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosShift;
use App\Models\StaffMember;
use App\Services\InventoryService;
use App\Services\PosService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Money;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Ordering\Models\OrderAdjustment;
use Juwri\Modules\Payments\Contracts\PaymentTerminal;
use Juwri\Modules\Pos\Models\PosOfflineCardPayment;
use Juwri\Modules\Pos\Models\PosOfflineDevice;
use Juwri\Modules\Pos\Models\PosOfflineRecord;
use Juwri\Modules\Pos\Models\PosOfflineSnapshot;
use Juwri\Modules\Pos\Pricing\TillCalculator;
use Throwable;

/**
 * Replays what a till recorded while offline (the offline POS spec §3, OP-5) — exactly once.
 *
 * Records are handled in the device's sequence order. Each one is keyed (store, client UUIDv7):
 *  - a key already stored with the SAME content answers `duplicate` with the first answer (nothing is applied again);
 *    the same key with DIFFERENT content answers `rejected: key_reused` and the first record stands;
 *  - a tender or void whose sale has not arrived yet answers `waiting` (nothing stored; the till sends it again);
 *  - otherwise the record is applied and stored in ONE transaction, so a crash between the effect and the answer
 *    cannot apply it twice (a racing copy hits the unique key and becomes a duplicate).
 * Final answers (accepted / conflict / rejected) are stored; `waiting` and `error` (an unexpected failure, rolled back)
 * are not, so the till retries them.
 *
 * The sale is booked at the till's prices (what the customer was shown and paid): lines carry the device unit and
 * modifier prices, and the totals the till declares must be what the shared TillCalculator gives for them with the
 * snapshot's tax — so the order's money is the till's money (§4.1). Tenders settle through PosService::settle, voids
 * through PosService::void, shifts through PosService::openShift / closeShift (the online code paths).
 *
 * The till's sequence numbers are tracked per device: gaps (records the server never saw) are reported, never block.
 * The clock skew of every batch is recorded; a sale's business time is the device time clamped to
 * [snapshot built, received].
 */
final class OfflineReplay
{
    public const MAX_RECORDS = 50;

    public const KEY = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';

    public const DEVICE = '/^[A-Za-z0-9._-]{8,64}$/D';

    public const TENDER_METHODS = ['cash', 'card'];

    private const ORDER_TYPES = ['takeaway', 'on-table'];

    /** @var array<string, int|null> */
    private array $shiftByKey = [];

    /**
     * @param  array<string, mixed>  $batch  {device_id, device_clock, branch_id?, records: [...]}
     * @return array{device: array<string, mixed>, results: list<array<string, mixed>>}
     */
    public function replay(Store $store, int $branchId, array $batch): array
    {
        $deviceId = (string) ($batch['device_id'] ?? '');
        $records = $batch['records'] ?? null;
        if (preg_match(self::DEVICE, $deviceId) !== 1 || ! is_array($records) || ! array_is_list($records) || count($records) > self::MAX_RECORDS) {
            throw new InvalidArgumentException('device_id (8-64 letters, digits, . _ -) and up to '.self::MAX_RECORDS.' records are required');
        }
        $now = CarbonImmutable::now();
        $device = $this->device($store, $branchId, $deviceId, $batch['device_clock'] ?? null, $now);

        usort($records, fn ($a, $b) => (int) (is_array($a) ? ($a['seq'] ?? 0) : 0) <=> (int) (is_array($b) ? ($b['seq'] ?? 0) : 0));
        $results = [];
        foreach ($records as $record) {
            $answer = is_array($record) ? $this->one($store, $branchId, $deviceId, $record, $now) : ['status' => 'rejected', 'reason' => 'invalid'];
            $results[] = ['key' => is_array($record) ? ($record['key'] ?? null) : null, 'seq' => is_array($record) ? ($record['seq'] ?? null) : null] + $answer;
            if (in_array($answer['status'], ['accepted', 'duplicate', 'conflict', 'rejected'], true) && is_array($record) && is_int($record['seq'] ?? null) && ($answer['reason'] ?? null) !== 'invalid') {
                $this->seen($device, (int) $record['seq']);
            }
        }
        $device->save();

        return ['device' => ['last_seq' => (int) $device->last_seq, 'missing_seqs' => array_values((array) $device->missing_seqs),
            'clock_skew_seconds' => $device->clock_skew_seconds], 'results' => $results];
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function one(Store $store, int $branchId, string $deviceId, array $r, CarbonImmutable $now): array
    {
        $key = $r['key'] ?? null;
        $type = $r['type'] ?? null;
        if (! is_string($key) || preg_match(self::KEY, $key) !== 1 || ! is_int($r['seq'] ?? null) || $r['seq'] < 1
            || ! in_array($type, ['sale', 'tender', 'void', 'drawer'], true) || ! is_array($r['payload'] ?? null)) {
            return ['status' => 'rejected', 'reason' => 'invalid'];
        }
        $hash = hash('sha256', OfflineSnapshot::canonical(['type' => $type, 'seq' => $r['seq'], 'staff_id' => $r['staff_id'] ?? null,
            'snapshot_version' => $r['snapshot_version'] ?? null, 'created_at' => $r['created_at'] ?? null, 'payload' => $r['payload']]));

        if (($existing = $this->record((int) $store->id, $key)) !== null) {
            return $this->repeat($existing, $hash);
        }

        $parent = null;
        if (in_array($type, ['tender', 'void'], true)) {
            $parentKey = $r['payload']['sale_key'] ?? null;
            $parent = is_string($parentKey) ? $this->record((int) $store->id, $parentKey) : null;
            if ($parent === null) {
                return is_string($parentKey) && preg_match(self::KEY, $parentKey) === 1 ? ['status' => 'waiting', 'reason' => 'parent_missing'] : $this->store($store, $branchId, $deviceId, $r, $hash, 'rejected', 'invalid');
            }
            if ($parent->type !== 'sale' || $parent->status !== 'accepted' || $parent->order_id === null) {
                return $this->store($store, $branchId, $deviceId, $r, $hash, 'rejected', 'parent_not_accepted');
            }
        }

        $snapshot = is_int($r['snapshot_version'] ?? null) ? app(OfflineSnapshot::class)->version((int) $store->id, $branchId, $r['snapshot_version']) : null;
        if ($snapshot === null) {
            return $this->store($store, $branchId, $deviceId, $r, $hash, 'rejected', 'snapshot_unknown');
        }
        $parts = (array) json_decode($snapshot->payload, true);
        $staff = $this->staff($store, $parts, $r['staff_id'] ?? null);
        if ($staff === null) {
            return $this->store($store, $branchId, $deviceId, $r, $hash, 'rejected', 'unknown_staff');
        }
        $needs = ['sale' => 'pos', 'tender' => 'pos', 'void' => 'pos_refund',
            'drawer' => ($r['payload']['action'] ?? null) === 'close' ? 'pos_shift_close' : 'pos_drawer_open'][$type];
        if (! in_array($needs, (array) ($staff['permissions'] ?? []), true)) {
            return $this->store($store, $branchId, $deviceId, $r, $hash, 'rejected', 'not_permitted');
        }
        $business = $this->businessTime($r['created_at'] ?? null, $snapshot, $now);

        try {
            return DB::transaction(function () use ($store, $branchId, $deviceId, $r, $hash, $type, $parts, $staff, $business, $parent) {
                [$status, $reason, $orderId, $result] = match ($type) {
                    'sale' => $this->sale($store, $branchId, $r, $parts, (int) $staff['id'], $business),
                    'tender' => $this->tender($store, $branchId, $deviceId, $r, $parts, $parent, $business),
                    'void' => $this->void($store, $deviceId, $r, $parts, (int) $staff['id'], $parent),
                    'drawer' => $this->drawer($store, $branchId, $deviceId, $r, (int) $staff['id'], $business),
                };
                if ($status !== 'accepted') {
                    throw new RefusedRecord($status, $reason, $result);
                }
                $stored = $this->store($store, $branchId, $deviceId, $r, $hash, 'accepted', $reason, $orderId, $result, $business);

                // already there (order_exists): kept as accepted (its tender still applies) but answered as a duplicate
                return $reason === 'order_exists' ? ['status' => 'duplicate'] + $stored : $stored;
            });
        } catch (RefusedRecord $refused) {
            return $this->store($store, $branchId, $deviceId, $r, $hash, $refused->status, $refused->reason, null, $refused->result);
        } catch (UniqueConstraintViolationException) {
            $raced = PosOfflineRecord::query()->where('store_id', $store->id)->where('client_key', $key)->first(); // a racing copy stored first

            return $raced instanceof PosOfflineRecord ? $this->repeat($raced, $hash) : ['status' => 'error', 'reason' => 'retry'];
        } catch (Throwable $e) {
            Log::warning('pos offline replay: record failed', ['store_id' => $store->id, 'device' => $deviceId, 'type' => $type, 'error' => get_class($e).': '.$e->getMessage()]);

            return ['status' => 'error', 'reason' => 'retry'];
        }
    }

    /**
     * @param  array<string, mixed>  $r
     * @param  array<string, mixed>  $parts
     * @return array{0: string, 1: ?string, 2: ?int, 3: array<string, mixed>}
     */
    private function sale(Store $store, int $branchId, array $r, array $parts, int $staffId, CarbonImmutable $business): array
    {
        $p = $r['payload'];
        $currency = (string) (($parts['store']['currency'] ?? null) ?: store_currency((int) $store->id));
        $items = collect((array) ($parts['catalogue']['items'] ?? []))->keyBy('id');
        $lines = (array) ($p['lines'] ?? []);
        if ($lines === [] || ! array_is_list($lines) || ! in_array($p['order_type'] ?? 'takeaway', self::ORDER_TYPES, true)) {
            return ['rejected', 'invalid', null, []];
        }
        $calcLines = [];
        foreach ($lines as $line) {
            if (! is_array($line) || ! $items->has($line['item_id'] ?? null)) {
                return ['rejected', 'unknown_item', null, []];
            }
            // an option sent by id must be one of this item's options in the sale's own snapshot
            foreach ((array) ($line['options'] ?? []) as $o) {
                if (is_array($o) && isset($o['id']) && self::snapshotOption((array) $items[$line['item_id']], $o['id']) === null) {
                    return ['rejected', 'unknown_option', null, []];
                }
            }
            $calcLines[] = ['unit_minor' => $line['unit_minor'] ?? null, 'qty' => $line['qty'] ?? null,
                'extras_minor' => array_map(fn ($e) => is_array($e) ? ($e['price_minor'] ?? null) : null, (array) ($line['extras'] ?? [])),
                'options_minor' => array_map(fn ($o) => is_array($o) ? ($o['price_minor'] ?? null) : null, (array) ($line['options'] ?? []))];
        }
        $calcIn = ['lines' => $calcLines, 'tax' => self::taxInput((array) ($parts['taxes'] ?? [])),
            'discount' => $p['discount'] ?? ['mode' => 'none'], 'service_minor' => $p['service_minor'] ?? 0, 'tip_minor' => $p['tip_minor'] ?? 0];
        try {
            $calc = TillCalculator::price($calcIn);
            // P6-271: a sale queued by a till still on the old calculator (tax before the discount) keeps that rule
            $grossBase = false;
            if (! self::sameTotals((array) ($p['totals'] ?? []), $calc)) {
                $gross = TillCalculator::price($calcIn + ['tax_base' => 'gross']);
                if (self::sameTotals((array) ($p['totals'] ?? []), $gross)) {
                    [$calc, $grossBase] = [$gross, true];
                }
            }
        } catch (InvalidArgumentException) {
            return ['rejected', 'invalid', null, []];
        }
        if (! self::sameTotals((array) ($p['totals'] ?? []), $calc)) {
            return ['conflict', 'pricing_mismatch', null, ['server' => $calc]];
        }

        $major = fn (int $minor): float => Money::majorFromMinor($minor, $currency);
        // The order may already exist under this key: the till's first online POST created it but the answer was lost, and
        // the queue sends it again. That is the same sale — a duplicate, never an error retried forever — as long as it is
        // the same money (else a manager looks at it).
        $existing = Order::query()->where('store_id', $store->id)->where('client_uuid', $r['key'])->first();
        if ($existing !== null) {
            $existing->load('items');
            $existing->snapshotTotals();
            $have = $existing->getAttribute('grand_total_minor');
            if ($existing->status === 'cancelled' || $have === null || (int) $have !== $calc['total_minor']) {
                return ['conflict', 'order_exists_mismatch', null, ['order_id' => (int) $existing->id, 'server' => $calc, 'order_total_minor' => $have === null ? null : (int) $have]];
            }

            return ['accepted', 'order_exists', (int) $existing->id, ['order_id' => (int) $existing->id, 'totals' => $calc, 'order_exists' => true]];
        }
        $order = Order::create([
            'store_id' => $store->id, 'branch_id' => $branchId ?: null, 'type' => $p['order_type'] ?? 'takeaway', 'source' => 'pos',
            'client_uuid' => $r['key'], 'status' => 'completed', 'completed_at' => $business, 'currency_code' => $currency,
            'discount_amount' => $major($calc['discount_minor']), 'service_charge_amount' => $calc['service_minor'] > 0 ? $major($calc['service_minor']) : null,
            'tip_amount' => $major($calc['tip_minor']), 'created_by_staff_id' => $staffId, 'is_paid' => 0, 'offline_tax_minor' => $calc['tax_minor'],
            'customer_name' => self::text($p['customer_name'] ?? null, 255), 'phone_number' => self::text($p['phone'] ?? null, 25),
            'tax_breakdown' => json_encode($calc['tax_minor'] > 0 ? [['label' => ___('Tax'), 'rate' => (float) (($parts['taxes']['rate'] ?? 0)), 'amount' => $major($calc['tax_minor'])]] : []),
            'created_at' => $business, 'updated_at' => $business,
        ]);
        if ($grossBase) {
            $order->forceFill(['tax_pre_discount' => true])->save(); // P6-271: the old rule, kept for this sale
        }
        $oversold = [];
        foreach ($lines as $line) {
            $item = $items[$line['item_id']];
            $groups = [];
            $extraTitles = collect((array) ($item['extras'] ?? []))->pluck('title', 'id');
            if (! empty($line['extras'])) {
                $groups[] = ['group_id' => 0, 'title' => ___('Extras'), 'selected' => array_map(fn ($e) => ['option' => (string) ($extraTitles[$e['id'] ?? 0] ?? ''),
                    'extra_id' => (int) ($e['id'] ?? 0), 'price' => $major((int) $e['price_minor'])], $line['extras'])];
            }
            foreach ((array) ($line['options'] ?? []) as $o) {
                // by id: the group and label come from the snapshot (what the till showed), not from the device's text
                $known = isset($o['id']) ? self::snapshotOption($item, $o['id']) : null;
                $groups[] = ['group_id' => $known['group_id'] ?? (int) ($o['group_id'] ?? 0), 'title' => $known['group'] ?? (string) ($o['group'] ?? ''),
                    'selected' => [array_filter(['option' => $known['label'] ?? (string) ($o['option'] ?? ''), 'option_id' => $known['id'] ?? null, 'price' => $major((int) $o['price_minor'])], fn ($v) => $v !== null)]];
            }
            OrderItem::create(['order_id' => $order->id, 'item_id' => (int) $line['item_id'], 'variation' => isset($line['variant_id']) ? (int) $line['variant_id'] : null,
                'quantity' => (int) $line['qty'], 'unit_price' => $major((int) $line['unit_minor']), 'options' => $groups, 'status' => 'served']);
            if (! $this->deduct($store, $order, (int) $line['item_id'], isset($line['variant_id']) ? (int) $line['variant_id'] : null, (int) $line['qty'])) {
                $oversold[] = (int) $line['item_id'];
            }
        }
        // The stored money columns (subtotal/tax/grand *_minor) through the same snapshot online orders get (they were
        // left empty, so reports that read the columns missed every offline sale).
        $order->load('items');
        $order->snapshotTotals();
        $flags = $this->saleFlags($store, $branchId, $lines, $parts, $staffId, $oversold);

        return ['accepted', null, (int) $order->id, array_filter(['order_id' => (int) $order->id, 'totals' => $calc, 'flags' => $flags])];
    }

    /**
     * @param  array<string, mixed>  $r
     * @param  array<string, mixed>  $parts
     * @return array{0: string, 1: ?string, 2: ?int, 3: array<string, mixed>}
     */
    private function tender(Store $store, int $branchId, string $deviceId, array $r, array $parts, PosOfflineRecord $sale, CarbonImmutable $business): array
    {
        $p = $r['payload'];
        $method = $p['method'] ?? null;
        $amount = $p['amount_minor'] ?? null;
        $tendered = $p['tendered_minor'] ?? null;
        $total = (int) ($sale->result['totals']['total_minor'] ?? -1);
        if (! in_array($method, [...self::TENDER_METHODS, 'card_offline'], true) || ! is_int($amount) || ($tendered !== null && (! is_int($tendered) || $tendered < $amount))) {
            return ['rejected', 'invalid', null, []];
        }
        if ($amount !== $total) {
            return ['rejected', 'amount_mismatch', null, ['sale_total_minor' => $total]];
        }
        $order = Order::query()->where('store_id', $store->id)->lockForUpdate()->find($sale->order_id);
        if ($order === null || $order->status === 'cancelled') {
            return ['conflict', 'order_changed', null, []];
        }
        if ($order->is_paid) {
            return ['conflict', 'already_paid', null, []];
        }
        $currency = (string) (($parts['store']['currency'] ?? null) ?: store_currency((int) $store->id));
        if ($method === 'card_offline') {
            return $this->cardOffline($store, $branchId, $deviceId, $r, $order, $amount, $currency, $business);
        }
        $order = PosService::settle($order, $method, $method === 'cash' && $tendered !== null ? Money::majorFromMinor($tendered, $currency) : null);
        $shiftKey = $p['shift_key'] ?? null;
        $flags = [];
        if (is_string($shiftKey) && ($shiftId = $this->shiftFor($store, $shiftKey)) !== null) {
            $order->forceFill(['pos_shift_id' => $shiftId])->save();
            if (PosShift::query()->whereKey($shiftId)->whereNotNull('closed_at')->exists()) {
                $flags['late_for_closed_shift'] = $shiftId; // §4.6: attached to the shift it was taken in; its Z report gains a late sale
            }
        }

        return ['accepted', null, (int) $order->id, array_filter(['order_id' => (int) $order->id, 'method' => $method, 'amount_minor' => $amount,
            'change_minor' => $order->change_due !== null ? Money::minorFromMajor((string) $order->change_due, $currency) : null, 'flags' => $flags], fn ($v) => $v !== [])];
    }

    /**
     * @param  array<string, mixed>  $parts
     * @param  array<string, mixed>  $r
     * @return array{0: string, 1: ?string, 2: ?int, 3: array<string, mixed>}
     */
    private function void(Store $store, string $deviceId, array $r, array $parts, int $staffId, PosOfflineRecord $sale): array
    {
        $p = $r['payload'];
        $line = $p['line'] ?? null;
        $reason = self::text($p['reason'] ?? null, 255);
        if (($line !== null && (! is_int($line) || $line < 0)) || $reason === null) {
            return ['rejected', 'invalid', null, []];
        }
        $order = Order::query()->where('store_id', $store->id)->find($sale->order_id);
        if ($order === null || OrderAdjustment::query()->where('order_id', $order->id)->where('type', 'refund')->exists()) {
            return ['conflict', 'order_changed', null, []]; // changed on the server since (e.g. refunded online): never applied twice
        }
        if ($sale->device_id !== $deviceId) {
            return ['conflict', 'not_this_device', null, []]; // §4.5: a till voids only its own offline sales
        }
        $itemId = null;
        if ($line !== null) {
            $itemId = OrderItem::query()->where('order_id', $order->id)->orderBy('id')->skip($line)->value('id');
            if ($itemId === null) {
                return ['conflict', 'line_gone', null, []];
            }
        }
        $adjustment = PosService::void($order, StaffMember::query()->find($staffId), $reason, $itemId !== null ? (int) $itemId : null);
        if ($itemId !== null && $order->offline_tax_minor !== null) {
            $this->retax($order->fresh() ?? $order, $parts);
        }

        return ['accepted', null, (int) $order->id, ['order_id' => (int) $order->id, 'adjustment_id' => (int) $adjustment->id]];
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array{0: string, 1: ?string, 2: ?int, 3: array<string, mixed>}
     */
    private function drawer(Store $store, int $branchId, string $deviceId, array $r, int $staffId, CarbonImmutable $business): array
    {
        $p = $r['payload'];
        $currency = (string) (store_options((int) $store->id, 'currency_code') ?: config('settings.currency_code', 'USD'));
        $staff = StaffMember::query()->find($staffId);
        $action = $p['action'] ?? null;
        if ($action === 'open' && is_int($p['opening_float_minor'] ?? null) && $p['opening_float_minor'] >= 0) {
            if (PosService::currentShift((int) $store->id, $branchId ?: null) !== null) {
                return ['conflict', 'shift_already_open', null, []];
            }
            try {
                $shift = PosService::openShift($store, $staff, $branchId ?: null, Money::majorFromMinor($p['opening_float_minor'], $currency));
            } catch (\RuntimeException) {
                // P6-343: another open won the race between currentShift() and openShift() — same conflict a manager would see online
                return ['conflict', 'shift_already_open', null, []];
            }

            return ['accepted', null, null, ['shift_id' => (int) $shift->id]];
        }
        if ($action === 'close' && is_int($p['counted_minor'] ?? null) && $p['counted_minor'] >= 0) {
            [$shift, $why] = $this->shiftToClose($store, $branchId, $deviceId, $p, $business);
            if ($shift === null) {
                return ['conflict', $why, null, []];
            }
            if ($why !== null) {
                // P6-343: shiftToClose kept the closed shift so the FIRST Z-report rides with the conflict
                return ['conflict', $why, null, ['shift_id' => (int) $shift->id,
                    'already_closed' => true, 'zreport' => PosService::zReport($shift)]];
            }
            $z = PosService::closeShift($shift, Money::majorFromMinor($p['counted_minor'], $currency));
            if (! empty($z['already_closed'])) {
                // P6-343: the online close beat us inside the transaction — never overwrite, hand back the FIRST Z-report unchanged
                return ['conflict', 'shift_already_closed', null, ['shift_id' => (int) $shift->id,
                    'already_closed' => true, 'zreport' => PosService::zReport($shift)]];
            }

            return ['accepted', null, null, ['shift_id' => (int) $shift->id]];
        }

        return ['rejected', 'invalid', null, []];
    }

    /**
     * P6-223: the shift this offline close was counted for — never simply "whatever is open now". The till names it by
     * the offline open's key (shift_key) or an online shift id (shift_id); an old client that names neither may close
     * the open shift only if this device opened it offline or it was already open when the till counted the drawer.
     *
     * @param  array<string, mixed>  $p
     * @return array{0: ?PosShift, 1: ?string}
     */
    private function shiftToClose(Store $store, int $branchId, string $deviceId, array $p, CarbonImmutable $business): array
    {
        $key = $p['shift_key'] ?? null;
        $id = $p['shift_id'] ?? null;
        if (is_string($key)) {
            $id = $this->shiftFor($store, $key);
        } elseif (! is_int($id)) {
            $open = PosService::currentShift((int) $store->id, $branchId ?: null);
            if ($open === null) {
                return [null, 'no_open_shift'];
            }
            $mine = PosOfflineRecord::query()->where('store_id', $store->id)->where('device_id', $deviceId)->where('type', 'drawer')
                ->where('status', 'accepted')->get(['result'])->contains(fn ($rec) => (int) ($rec->result['shift_id'] ?? 0) === (int) $open->id);
            if (! $mine && $business->lessThan($open->opened_at)) {
                return [null, 'shift_changed'];
            }
            $id = (int) $open->id;
        }
        $shift = is_int($id) && $id > 0
            ? PosShift::query()->where('store_id', $store->id)->where('branch_id', $branchId ?: null)->whereKey($id)->lockForUpdate()->first()
            : null;
        if ($shift === null) {
            return [null, 'shift_not_found'];
        }
        if ($shift->status !== 'open') {
            // P6-343: keep the closed shift — the FIRST Z-report rides with the conflict (the online close beat us,
            // and a replayed offline close must see the same numbers)
            return [$shift, 'shift_already_closed'];
        }

        return [$shift, null];
    }

    /** Offline sales already happened: stock is taken even below zero (owner Q4: allow and reconcile). False = oversold. */
    private function deduct(Store $store, Order $order, int $menuId, ?int $variantId, int $qty): bool
    {
        $enough = true;
        if (inventory_mode((int) $store->id) === 'advanced') {
            InventoryService::deductForOrderItem($menuId, $qty, $order->id, $order->branch_id ?: null);
        } else {
            $enough = Menu::deductStock($menuId, $qty);
        }
        if ($variantId) {
            $enough = MenuVariant::deductStock($variantId, $qty) && $enough;
        }

        return $enough;
    }

    /**
     * OFFLINE-POS-SPEC §4.3 / §4.4 / §4.6: what changed between the till's snapshot and now. The sale is booked as
     * sold either way; these flags put it on the manager's offline exceptions.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $parts  the snapshot version the till sold with
     * @param  list<int>  $oversold
     * @return array<string, mixed>
     */
    private function saleFlags(Store $store, int $branchId, array $lines, array $parts, int $staffId, array $oversold): array
    {
        $now = $this->currentParts($store, $branchId);
        $then = collect((array) ($parts['catalogue']['items'] ?? []))->keyBy('id');
        $current = collect((array) ($now['catalogue']['items'] ?? []))->keyBy('id');
        $flags = [];
        foreach ($lines as $line) {
            $id = (int) $line['item_id'];
            $unit = (int) $line['unit_minor'];
            $listed = isset($line['variant_id']) ? collect((array) ($then[$id]['variants'] ?? []))->firstWhere('id', (int) $line['variant_id'])['price_minor'] ?? null : ($then[$id]['price_minor'] ?? null);
            if ($listed !== null && $listed !== $unit) {
                $flags['price_not_in_snapshot'][] = ['item_id' => $id, 'sold_minor' => $unit, 'snapshot_minor' => $listed];
            }
            foreach ((array) ($line['options'] ?? []) as $o) {
                $known = is_array($o) && isset($o['id']) ? self::snapshotOption((array) ($then[$id] ?? []), $o['id']) : null;
                if ($known !== null && $known['price_minor'] !== (int) ($o['price_minor'] ?? -1)) {
                    $flags['price_not_in_snapshot'][] = ['item_id' => $id, 'option_id' => $known['id'], 'sold_minor' => (int) ($o['price_minor'] ?? 0), 'snapshot_minor' => $known['price_minor']];
                }
            }
            if (! $current->has($id)) {
                $flags['item_gone'][] = $id;

                continue;
            }
            $nowPrice = isset($line['variant_id']) ? collect((array) ($current[$id]['variants'] ?? []))->firstWhere('id', (int) $line['variant_id'])['price_minor'] ?? null : $current[$id]['price_minor'];
            if ($nowPrice !== null && $nowPrice !== $unit && $listed === $unit) {
                $flags['priced_on_old_snapshot'][] = ['item_id' => $id, 'sold_minor' => $unit, 'current_minor' => $nowPrice];
            }
            if (! empty($current[$id]['sold_out'])) {
                $flags['sold_out_since'][] = $id;
            }
        }
        if (self::taxInput((array) ($parts['taxes'] ?? [])) !== self::taxInput((array) ($now['taxes'] ?? []))) {
            $flags['tax_changed'] = ['sold' => self::taxInput((array) ($parts['taxes'] ?? [])), 'current' => self::taxInput((array) ($now['taxes'] ?? []))];
        }
        if ($oversold !== []) {
            $flags['oversell'] = array_values(array_unique($oversold));
        }
        if (! collect((array) ($now['staff'] ?? []))->contains('id', $staffId)) {
            $flags['staff_changed'] = $staffId;
        }

        return $flags;
    }

    /** @var array<string, array<string, mixed>> */
    private array $currentParts = [];

    /** @return array<string, mixed> */
    private function currentParts(Store $store, int $branchId): array
    {
        return $this->currentParts[$store->id.':'.$branchId] ??= (array) json_decode(app(OfflineSnapshot::class)->current($store, $branchId)->payload, true);
    }

    /**
     * OFFLINE-POS-SPEC §7: a card the provider SDK took offline (deferred authorisation). Only when the platform switch is
     * on AND the store's provider can do it (owner Q1); we keep the provider reference, never card data. The tender is
     * `pending_offline` — the order is NOT paid until the provider confirms (OfflineCardSettlement). The caps (owner Q3:
     * per payment and the unforwarded total per till, the store's own lower values if set) are enforced on the till
     * before the card is read; a replayed tender above them happened and is booked, flagged `cap_exceeded`.
     *
     * @param  array<string, mixed>  $r
     * @return array{0: string, 1: ?string, 2: ?int, 3: array<string, mixed>}
     */
    private function cardOffline(Store $store, int $branchId, string $deviceId, array $r, Order $order, int $amount, string $currency, CarbonImmutable $business): array
    {
        $terminal = app(PaymentTerminal::class);
        if (! config('pos.offline.card_enabled') || ! $terminal->supportsOffline($store)) {
            return ['rejected', 'card_offline_unavailable', null, []];
        }
        $reference = $r['payload']['sdk_reference'] ?? null;
        if (! is_string($reference) || preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $reference) !== 1) {
            return ['rejected', 'invalid', null, []];
        }
        [$capAmount, $capTotal] = OfflineSnapshot::cardCaps($store);
        $unforwarded = (int) PosOfflineCardPayment::query()->where('store_id', $store->id)->where('device_id', $deviceId)->where('state', 'pending_offline')->sum('amount_minor');
        $flags = [];
        if ($amount > $capAmount || $unforwarded + $amount > $capTotal) {
            $flags['cap_exceeded'] = ['amount_minor' => $amount, 'unforwarded_minor' => $unforwarded, 'cap_amount_minor' => $capAmount, 'cap_total_minor' => $capTotal];
        }
        $payment = PosOfflineCardPayment::query()->create(['account_id' => $store->account_id, 'store_id' => $store->id, 'branch_id' => $branchId ?: null,
            'order_id' => $order->id, 'client_key' => $r['key'], 'device_id' => $deviceId, 'provider' => $terminal->key(), 'provider_reference' => $reference,
            'amount_minor' => $amount, 'currency_code' => $currency, 'cap_amount_minor' => $capAmount, 'cap_total_minor' => $capTotal,
            'state' => 'pending_offline', 'taken_at' => $business]);
        $order->forceFill(['payment_method' => 'card_offline'])->save(); // not paid: never counted until the provider confirms
        $shiftKey = $r['payload']['shift_key'] ?? null;
        if (is_string($shiftKey) && ($shiftId = $this->shiftFor($store, $shiftKey)) !== null) {
            $order->forceFill(['pos_shift_id' => $shiftId])->save();
        }

        return ['accepted', null, (int) $order->id, array_filter(['order_id' => (int) $order->id, 'method' => 'card_offline', 'amount_minor' => $amount,
            'card_payment_id' => (int) $payment->id, 'state' => 'pending_offline', 'flags' => $flags], fn ($v) => $v !== [])];
    }

    /**
     * The till's declared totals equal the server's.
     *
     * @param  array<string, mixed>  $declared
     * @param  array<string, mixed>  $calc
     */
    private static function sameTotals(array $declared, array $calc): bool
    {
        foreach (['subtotal_minor', 'discount_minor', 'tax_minor', 'service_minor', 'tip_minor', 'total_minor'] as $k) {
            if (($declared[$k] ?? null) !== $calc[$k]) {
                return false;
            }
        }

        return true;
    }

    /**
     * A line voided on an offline sale: its kept tax is recomputed for the remaining lines with the till's tax rule.
     *
     * @param  array<string, mixed>  $parts
     */
    private function retax(Order $order, array $parts): void
    {
        $currency = (string) (($parts['store']['currency'] ?? null) ?: store_currency((int) $order->store_id));
        $lines = [];
        foreach ($order->items()->get() as $item) {
            $adds = [];
            foreach ((array) $item->options as $grp) {
                foreach ((array) ($grp['selected'] ?? []) as $opt) {
                    $adds[] = Money::minorFromMajor((string) ($opt['price'] ?? 0), $currency);
                }
            }
            $lines[] = ['unit_minor' => Money::minorFromMajor((string) $item->unit_price, $currency), 'qty' => (int) $item->quantity, 'extras_minor' => $adds];
        }
        // P6-271: the order's own discount and tax base (after the discount; before it for an old-rule sale)
        $calc = TillCalculator::price(['lines' => $lines, 'tax' => self::taxInput((array) ($parts['taxes'] ?? [])),
            'discount' => ['mode' => 'amount', 'amount_minor' => Money::minorFromMajor((float) ($order->discount_amount ?? 0), $currency)],
            'tax_base' => $order->getAttribute('tax_pre_discount') ? 'gross' : 'net']);
        $order->forceFill(['offline_tax_minor' => $calc['tax_minor']])->save();
    }

    private function shiftFor(Store $store, string $drawerKey): ?int
    {
        if (! array_key_exists($drawerKey, $this->shiftByKey)) {
            $rec = $this->record((int) $store->id, $drawerKey);
            $id = $rec !== null && $rec->type === 'drawer' && $rec->status === 'accepted' ? (int) ($rec->result['shift_id'] ?? 0) : 0;
            $this->shiftByKey[$drawerKey] = $id > 0 && PosShift::query()->where('store_id', $store->id)->whereKey($id)->exists() ? $id : null;
        }

        return $this->shiftByKey[$drawerKey];
    }

    /**
     * The staff member as the snapshot knew them (their permissions then), and still a staff row of this store.
     *
     * @param  array<string, mixed>  $parts
     * @return array<string, mixed>|null
     */
    private function staff(Store $store, array $parts, mixed $staffId): ?array
    {
        if (! is_int($staffId)) {
            return null;
        }
        $known = collect((array) ($parts['staff'] ?? []))->firstWhere('id', $staffId);

        return is_array($known) && StaffMember::query()->where('store_id', $store->id)->whereKey($staffId)->exists() ? $known : null;
    }

    /**
     * @param  array<string, mixed>  $taxes
     * @return array<string, mixed>
     */
    public static function taxInput(array $taxes): array
    {
        return match ($taxes['mode'] ?? 'none') {
            'percentage' => ['mode' => 'percentage', 'rate' => (string) $taxes['rate']],
            'fixed' => ['mode' => 'fixed', 'amount_minor' => (int) $taxes['amount_minor']],
            default => ['mode' => 'none'],
        };
    }

    private function businessTime(mixed $deviceTime, PosOfflineSnapshot $snapshot, CarbonImmutable $now): CarbonImmutable
    {
        try {
            $t = is_string($deviceTime) ? CarbonImmutable::parse($deviceTime) : $now;
        } catch (Throwable) {
            $t = $now;
        }
        $built = CarbonImmutable::parse((string) $snapshot->getRawOriginal('created_at'));

        return $t->lessThan($built) ? $built : ($t->greaterThan($now) ? $now : $t);
    }

    private function device(Store $store, int $branchId, string $deviceId, mixed $clock, CarbonImmutable $now): PosOfflineDevice
    {
        $device = PosOfflineDevice::query()->firstOrCreate(['store_id' => $store->id, 'device_id' => $deviceId],
            ['account_id' => $store->account_id, 'branch_id' => $branchId ?: null, 'last_seq' => 0, 'missing_seqs' => []]);
        try {
            $device->clock_skew_seconds = is_string($clock) ? (int) $now->diffInSeconds(CarbonImmutable::parse($clock), false) * -1 : null;
        } catch (Throwable) {
            $device->clock_skew_seconds = null;
        }
        $device->last_seen_at = $now;

        return $device;
    }

    /** Track the device's sequence: a jump leaves the skipped numbers as missing (capped), a late one fills its gap. */
    private function seen(PosOfflineDevice $device, int $seq): void
    {
        $missing = array_map('intval', (array) $device->missing_seqs);
        $last = (int) $device->last_seq;
        if ($seq > $last) {
            if ($seq > $last + 1) {
                $missing = array_merge($missing, range($last + 1, $seq - 1));
            }
            $device->last_seq = $seq;
        } else {
            $missing = array_values(array_diff($missing, [$seq]));
        }
        $missing = array_values(array_unique($missing));
        sort($missing);
        $device->missing_seqs = array_slice($missing, -1000);
    }

    private function record(int $storeId, string $key): ?PosOfflineRecord
    {
        return PosOfflineRecord::query()->where('store_id', $storeId)->where('client_key', $key)->first();
    }

    /** @return array<string, mixed> */
    private function repeat(PosOfflineRecord $existing, string $hash): array
    {
        if (! hash_equals($existing->payload_hash, $hash)) {
            return ['status' => 'rejected', 'reason' => 'key_reused'];
        }

        return ['status' => $existing->status === 'accepted' ? 'duplicate' : $existing->status, 'reason' => $existing->reason,
            'result' => $existing->result, 'duplicate' => true];
    }

    /**
     * @param  array<string, mixed>  $r
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function store(Store $store, int $branchId, string $deviceId, array $r, string $hash, string $status, ?string $reason,
        ?int $orderId = null, array $result = [], ?CarbonImmutable $business = null): array
    {
        try {
            $device = null;
            try {
                $device = is_string($r['created_at'] ?? null) ? CarbonImmutable::parse($r['created_at']) : null;
            } catch (Throwable) {
            }
            PosOfflineRecord::query()->create([
                'account_id' => $store->account_id, 'store_id' => $store->id, 'branch_id' => $branchId ?: null, 'device_id' => $deviceId,
                'device_seq' => (int) $r['seq'], 'client_key' => (string) $r['key'], 'type' => (string) $r['type'], 'payload_hash' => $hash,
                'status' => $status, 'reason' => $reason, 'order_id' => $orderId, 'staff_id' => is_int($r['staff_id'] ?? null) ? $r['staff_id'] : null,
                'snapshot_version' => is_int($r['snapshot_version'] ?? null) ? $r['snapshot_version'] : null,
                'result' => $result ?: null, 'flags' => ($result['flags'] ?? null) ?: null, 'device_created_at' => $device, 'business_at' => $business, 'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException $e) {
            if ($status === 'accepted') {
                throw $e; // inside the effect's transaction: roll it back, the first copy stands
            }
            $existing = $this->record((int) $store->id, (string) $r['key']);

            return $existing !== null ? $this->repeat($existing, $hash) : ['status' => 'error', 'reason' => 'retry'];
        }

        return array_filter(['status' => $status, 'reason' => $reason, 'result' => $result ?: null], fn ($v) => $v !== null);
    }

    private static function text(mixed $v, int $max): ?string
    {
        $s = is_string($v) ? trim($v) : '';

        return $s === '' ? null : mb_substr($s, 0, $max);
    }

    /**
     * An item's choice option by its shared id ('<group id>:<position>'), from a snapshot item.
     *
     * @param  array<string, mixed>  $item
     * @return array{id: string, group_id: int, group: string, label: string, price_minor: int}|null
     */
    private static function snapshotOption(array $item, mixed $id): ?array
    {
        if (! is_string($id)) {
            return null;
        }
        foreach ((array) ($item['choice_groups'] ?? []) as $g) {
            foreach ((array) ($g['options'] ?? []) as $o) {
                if (($o['id'] ?? null) === $id) {
                    return ['id' => $id, 'group_id' => (int) ($g['id'] ?? 0), 'group' => (string) ($g['title'] ?? ''), 'label' => (string) ($o['label'] ?? ''), 'price_minor' => (int) ($o['price_minor'] ?? 0)];
                }
            }
        }

        return null;
    }
}
