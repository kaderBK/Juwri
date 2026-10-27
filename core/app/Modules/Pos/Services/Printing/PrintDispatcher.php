<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Services\Printing;

use App\Models\KitchenStation;
use App\Models\Order;
use App\Models\PosShift;
use App\Services\FeatureAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Models\Store;
use Juwri\Modules\Pos\Models\PrinterProfile;
use Juwri\Modules\Pos\Models\PrintJob;

/**
 * PARITY-RUN T1-3: the print-job dispatcher — the one place that decides
 * WHICH printers get WHICH bytes for an event.
 *
 * Resolution is event × station × branch:
 *   • event   — the profile must subscribe to it (`events` JSON);
 *   • branch  — a profile with no branch is the store-wide default and serves
 *               every branch; a branch profile only serves its own;
 *   • station — KOT only. An empty station filter means "one ticket, every
 *               line"; a filter means the ticket carries only that station's
 *               lines, which is what "a different KOT for kitchen and bar" is.
 *
 * Delivery is deliberately NOT the dispatcher's job. It renders and parks a
 * PrintJob; whoever owns the device handle (the POS page for Web Serial/WebUSB,
 * Android for RawBT, the desktop shell for the bridge) claims it and pushes the
 * bytes. The one exception is a single-host install (`printing.server_bridge`),
 * where this process can reach the bridge itself.
 *
 * Every entry point is best-effort and swallows its own errors: a printer that
 * is off must never fail an order transition. That is the same guarded shape as
 * the two existing listeners on Order::booted().
 */
class PrintDispatcher
{
    public const FEATURE = 'print_routing';

    /** Is printing available for this store (superadmin kill-switch + plan)? */
    public static function enabled(?int $storeId): bool
    {
        return FeatureAccess::storeAllows($storeId, self::FEATURE);
    }

    /**
     * Profiles that should print `$event` for `$branchId`, ordered so the owner's
     * explicit ordering wins. Only ACTIVE, auto-printing profiles are returned —
     * a manual profile still exists for the POS print menu and test prints.
     */
    public static function profilesFor(int $storeId, string $event, ?int $branchId, bool $autoOnly = true): Collection
    {
        $query = PrinterProfile::where('store_id', $storeId)->where('active', true);
        if ($autoOnly) {
            $query->where('auto_print', true);
        }

        return $query->orderBy('position')->orderBy('id')->get()
            ->filter(fn (PrinterProfile $p) => $p->handlesEvent($event) && $p->servesBranch($branchId))
            ->values();
    }

    // ── triggers ─────────────────────────────────────────────────────────────

    /**
     * A new order reached the kitchen: fire `new_order` receipts and per-station
     * KOT tickets. Called from OrderApiService::create — the ONE creation path —
     * so POS, QR storefront, agent, API and aggregator orders all print.
     */
    public static function onOrderCreated(Order $order): int
    {
        return self::guard(function () use ($order) {
            if ($order->held_at || $order->isPendingSchedule()) {
                return 0; // parked / not yet released — nothing has happened yet
            }

            return self::dispatchOrder($order, 'new_order') + self::dispatchOrder($order, 'kot');
        });
    }

    /** An order was settled: fire `paid` receipts. */
    public static function onOrderPaid(Order $order): int
    {
        return self::guard(fn () => self::dispatchOrder($order, 'paid'));
    }

    /**
     * A held order was released / fired to the kitchen. Same tickets as a new
     * order; the job keys are identical, so a resume after a create never
     * double-prints.
     */
    public static function onOrderReleased(Order $order): int
    {
        return self::onOrderCreated($order);
    }

    /**
     * Items were appended to an existing order (POS "Add products"): fire an
     * add-on KOT carrying ONLY the new lines, for every auto-print kitchen
     * profile. The job key carries the max new item id, so a replay of the same
     * append never prints a second add-on ticket, while a later append (higher
     * ids) is a distinct ticket.
     *
     * @param  array<int,int>  $newOrderItemIds
     */
    public static function onItemsAppended(Order $order, array $newOrderItemIds): int
    {
        return self::guard(function () use ($order, $newOrderItemIds) {
            $ids = array_values(array_filter(array_map('intval', $newOrderItemIds)));
            if ($ids === [] || $order->held_at) {
                return 0;
            }

            $rid = (int) $order->store_id;
            if (! self::enabled($rid)) {
                return 0;
            }

            $branchId = $order->branch_id ? (int) $order->branch_id : null;
            $count = 0;

            foreach (self::profilesFor($rid, 'kot', $branchId) as $profile) {
                $bytes = self::renderAddOnKot($profile, $order, $ids);
                if ($bytes === null) {
                    continue;
                }

                $job = self::queue($profile, 'kot', $bytes, [
                    'order_id' => $order->id,
                    'job_key' => self::jobKey('ord', (int) $order->id, $profile, 'kot-add-'.max($ids)),
                ]);
                $count += $job ? 1 : 0;
            }

            return $count;
        });
    }

    /** A drawer shift closed: fire the Z summary. */
    public static function onShiftClosed(PosShift $shift, array $zreport): int
    {
        return self::guard(function () use ($shift, $zreport) {
            $rid = (int) $shift->store_id;
            if (! self::enabled($rid)) {
                return 0;
            }

            $count = 0;
            foreach (self::profilesFor($rid, 'shift_close', $shift->branch_id ? (int) $shift->branch_id : null) as $profile) {
                $bytes = EscPosRenderer::forProfile($profile)->renderShiftSummary($shift, $zreport);
                $job = self::queue($profile, 'shift_close', $bytes, [
                    'shift_id' => $shift->id,
                    'job_key' => self::jobKey('shf', (int) $shift->id, $profile, 'shift_close'),
                ]);
                $count += $job ? 1 : 0;
            }

            return $count;
        });
    }

    /** Owner-triggered test page for one profile. Always queues, ignoring auto_print. */
    public static function testPrint(PrinterProfile $profile): ?PrintJob
    {
        $store = Store::find($profile->store_id);
        if (! $store) {
            return null;
        }

        $bytes = EscPosRenderer::forProfile($profile)->renderTest($store, (string) $profile->name);

        // A test print is explicitly repeatable — the key carries a timestamp so
        // the second press actually prints again.
        return self::queue($profile, 'test', $bytes, [
            'job_key' => self::jobKey('tst', (int) (microtime(true) * 1000) % 1000000000, $profile, 'test'),
        ]);
    }

    /**
     * Manual print of one document for one profile, from the POS print menu.
     * `$event` picks the document; the profile need not subscribe to it.
     *
     * The default `$reprint = true` is deliberate: only the manual POS menu calls
     * this, so a reprint must actually print again. The key carries a reprint
     * suffix keyed to a 5-second window (a double-tap collapses onto one ticket)
     * instead of the automatic-print key, which would have been firstOrCreate'd
     * back to the already-printed job.
     */
    public static function printOrderTo(PrinterProfile $profile, Order $order, string $event, bool $reprint = true): ?PrintJob
    {
        $bytes = self::renderFor($profile, $order, $event, $reprint);
        if ($bytes === null) {
            return null;
        }

        $jobKey = $reprint
            ? substr(self::jobKey('ord', (int) $order->id, $profile, $event), 0, 50).'-re-'.intdiv(now()->getTimestamp(), 5)
            : self::jobKey('ord', (int) $order->id, $profile, $event);

        return self::queue($profile, $event, $bytes, [
            'order_id' => $order->id,
            'job_key' => $jobKey,
        ]);
    }

    // ── internals ────────────────────────────────────────────────────────────

    /** Render + queue one event across every matching profile. */
    private static function dispatchOrder(Order $order, string $event): int
    {
        $rid = (int) $order->store_id;
        if (! self::enabled($rid)) {
            return 0;
        }

        $branchId = $order->branch_id ? (int) $order->branch_id : null;
        $count = 0;

        foreach (self::profilesFor($rid, $event, $branchId) as $profile) {
            $bytes = self::renderFor($profile, $order, $event);
            if ($bytes === null) {
                continue;
            }

            $job = self::queue($profile, $event, $bytes, [
                'order_id' => $order->id,
                'job_key' => self::jobKey('ord', (int) $order->id, $profile, $event),
            ]);
            $count += $job ? 1 : 0;
        }

        return $count;
    }

    /**
     * The document for an event. Returns null when there is nothing to print —
     * most importantly a KOT whose stations produced no lines, so a bar printer
     * stays quiet on a food-only order instead of spitting an empty ticket.
     */
    private static function renderFor(PrinterProfile $profile, Order $order, string $event, bool $reprint = false): ?string
    {
        $renderer = EscPosRenderer::forProfile($profile);

        if ($event === 'kot') {
            $stations = $profile->stationFilter();
            if ($stations && $renderer->itemsForStations($order, $stations)->isEmpty()) {
                return null;
            }

            return $renderer->renderKot($order, $stations, self::stationLabel($profile), $reprint);
        }

        $drawer = $event === 'paid' && (bool) store_options((int) $order->store_id, 'print_open_drawer');

        return $renderer->renderReceipt($order, $drawer, $reprint);
    }

    /** Heading printed on a station ticket — the profile's own name reads best on paper. */
    private static function stationLabel(PrinterProfile $profile): ?string
    {
        $names = KitchenStation::whereIn('id', $profile->stationFilter())->pluck('name')->all();

        return $names ? implode(' / ', $names) : ($profile->name ?: null);
    }

    /**
     * The add-on KOT document for a profile: only the appended lines, still
     * station-filtered, with the ADD-ON header. Null when that filter leaves no
     * lines — a kitchen printer whose new items all went to the bar stays quiet.
     *
     * @param  array<int,int>  $onlyItemIds
     */
    private static function renderAddOnKot(PrinterProfile $profile, Order $order, array $onlyItemIds): ?string
    {
        $renderer = EscPosRenderer::forProfile($profile);
        $stations = $profile->stationFilter();

        if ($renderer->itemsForStations($order, $stations, $onlyItemIds)->isEmpty()) {
            return null;
        }

        return $renderer->renderKot($order, $stations, self::stationLabel($profile), false, $onlyItemIds, true);
    }

    /**
     * Park a rendered ticket. `job_key` is UNIQUE, so a status replay, an offline
     * outbox retry and a duplicate listener invocation all resolve to the SAME
     * row and the same physical ticket. firstOrCreate is the whole guarantee.
     */
    private static function queue(PrinterProfile $profile, string $event, string $bytes, array $attrs = []): ?PrintJob
    {
        if ($bytes === '' || strlen($bytes) > (int) config('printing.max_payload_bytes')) {
            return null;
        }

        $jobKey = (string) ($attrs['job_key'] ?? self::jobKey('job', 0, $profile, $event));

        $job = PrintJob::firstOrCreate(['job_key' => $jobKey], [
            'store_id' => $profile->store_id,
            'branch_id' => $profile->branch_id,
            'profile_id' => $profile->id,
            'order_id' => $attrs['order_id'] ?? null,
            'shift_id' => $attrs['shift_id'] ?? null,
            'event' => $event,
            'transport' => $profile->transport,
            'target' => $profile->target,
            'width_mm' => $profile->width_mm,
            'copies' => max(1, (int) $profile->copies),
            'payload' => base64_encode($bytes),
            'status' => 'pending',
        ]);

        if (! $job->wasRecentlyCreated) {
            return $job; // already queued/printed — never render a second ticket
        }

        // Single-host install: this process can actually reach the loopback
        // bridge, so deliver now instead of waiting for a page to collect it.
        if ($profile->transport === 'bridge' && config('printing.server_bridge')) {
            self::deliverViaBridge($job, $profile, $bytes);
        }

        return $job;
    }

    /** Server-side bridge delivery (single-host installs only). */
    public static function deliverViaBridge(PrintJob $job, PrinterProfile $profile, string $bytes): void
    {
        try {
            // P6-298: the server calls only the operator's bridge or a vetted public host,
            // never an owner-typed internal/metadata address (the pairing token rides along).
            $options = BridgeUrl::serverOptions($profile->bridgeBase());
            if ($options === null) {
                Log::warning('print.bridge_blocked', ['profile' => $profile->id, 'store' => $profile->store_id]);
                $job->markFailed('econnrefused');
                self::requeueToFallback($job);

                return;
            }
            $client = new PrintBridgeClient($profile->bridgeBase(), PrintSettings::pairingToken((int) $profile->store_id), $options);
            $res = $client->print($job->job_key, (string) $profile->target, (int) $profile->width_mm, $bytes);

            if (! empty($res['ok'])) {
                $job->markDone();
            } else {
                $job->markFailed((string) ($res['error'] ?? 'internal'));
                self::requeueToFallback($job);
            }
        } catch (\Throwable $e) {
            $job->markFailed('internal');
            self::requeueToFallback($job);
        }
    }

    /**
     * D3 — re-route a FAILED job to its profile's fallback printer, exactly once.
     * The already-rendered bytes are re-sent to the fallback device (same width, so
     * the ESC/POS byte stream stays valid); only transport/target change. Guards:
     *   · the job must be failed and its profile must name an active fallback;
     *   · a job that is ITSELF a fallback (fallback_of_job_id set) never chains
     *     again — no fallback-of-fallback loop;
     *   · a fallback job for this original is created at most once (job_key unique).
     * Returns the new job, or null when nothing was re-routed.
     */
    public static function requeueToFallback(PrintJob $job): ?PrintJob
    {
        if ($job->status !== 'failed' || $job->fallback_of_job_id !== null) {
            return null; // not failed, or already a fallback → never chain
        }

        $profile = $job->profile;
        if (! $profile || $profile->fallback_profile_id === null) {
            return null;
        }

        $fallback = PrinterProfile::where('id', $profile->fallback_profile_id)
            ->where('store_id', $job->store_id)
            ->where('active', true)->first();
        if (! $fallback || (int) $fallback->id === (int) $profile->id) {
            return null; // missing, disabled, or self-referential
        }

        $newKey = substr($job->job_key.'-fb', 0, 64);

        // Reuse the rendered payload (already base64 in the failed row); keep its
        // width so the bytes remain valid, but send to the fallback device.
        $new = PrintJob::firstOrCreate(['job_key' => $newKey], [
            'store_id' => $job->store_id,
            'branch_id' => $job->branch_id,
            'profile_id' => $fallback->id,
            'fallback_of_job_id' => $job->id,
            'order_id' => $job->order_id,
            'shift_id' => $job->shift_id,
            'event' => $job->event,
            'transport' => $fallback->transport,
            'target' => $fallback->target,
            'width_mm' => $job->width_mm,
            'copies' => $job->copies,
            'payload' => $job->payload,
            'status' => 'pending',
        ]);

        // Single-host bridge: deliver immediately, exactly like queue() does.
        if ($new->wasRecentlyCreated
            && $fallback->transport === 'bridge' && config('printing.server_bridge')
            && $new->payload) {
            self::deliverViaBridge($new, $fallback, (string) base64_decode((string) $new->payload));
        }

        return $new;
    }

    /**
     * `<kind>-<id>-<profile-slug>-<event>`, ≤ 64 chars — the same string the web
     * side sends to the bridge as `jobId` (print-bridge.md §Idempotency), so the
     * server queue and the bridge's replay cache agree on what "the same ticket"
     * means.
     */
    public static function jobKey(string $kind, int $id, PrinterProfile $profile, string $event): string
    {
        return substr($kind.'-'.$id.'-'.$profile->slug().'-'.$event, 0, 64);
    }

    /** Best-effort wrapper — printing must never break the flow that triggered it. */
    private static function guard(callable $fn): int
    {
        try {
            return (int) $fn();
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
    }
}
