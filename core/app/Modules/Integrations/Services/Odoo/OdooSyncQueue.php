<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Services\Odoo;

use App\Models\Order;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Services\Tenancy\TenantContext;
use Juwri\Core\Support\PlanGate;
use Juwri\Modules\Integrations\Jobs\SyncOdooRecordJob;
use Juwri\Modules\Integrations\Models\OdooSyncRecord;
use Juwri\Modules\Ordering\Models\OrderAdjustment;

/**
 * P4-21: the Odoo retry queue. A paid order and each refund / void of a connected store get one row
 * ({@see OdooSyncRecord}) in the same transaction as the money change (a rolled-back payment queues nothing);
 * the job runs after commit. A failure keeps the row and schedules the next attempt with backoff
 * ({@see BACKOFF}); `odoo:sync-due` (every 5 minutes) re-runs due rows; after the last attempt the row is `failed`
 * and the owner's Odoo screen offers Retry. Every path checks the plan gate (Community: always allowed).
 */
final class OdooSyncQueue
{
    /** Seconds before attempt 2, 3, … (1 min → 1 day); the row fails after the last. */
    public const BACKOFF = [60, 300, 900, 3600, 10800, 43200, 86400];

    public function __construct(private readonly OdooSync $sync) {}

    public static function orderPaid(Order $order): void
    {
        $storeId = (int) $order->store_id;
        if (! self::wanted($storeId)) {
            return;
        }
        if (empty($order->public_id)) {
            $order->forceFill(['public_id' => (string) Str::uuid7()])->saveQuietly();
        }
        self::queue($storeId, 'order', (int) $order->id, (int) $order->id, (string) $order->public_id);
    }

    public static function adjusted(OrderAdjustment $adjustment): void
    {
        if (! in_array((string) $adjustment->type, ['refund', 'void'], true) || (int) $adjustment->amount_minor <= 0) {
            return;
        }
        $order = Order::query()->find($adjustment->order_id);
        if (! $order || ! self::wanted((int) $order->store_id)) {
            return;
        }
        if (empty($order->public_id)) {
            $order->forceFill(['public_id' => (string) Str::uuid7()])->saveQuietly();
        }
        self::queue((int) $order->store_id, 'refund', (int) $adjustment->id, (int) $order->id, $order->public_id.'/refund/'.$adjustment->id);
    }

    private static function wanted(int $storeId): bool
    {
        return $storeId > 0 && OdooConnection::active($storeId) && PlanGate::storeAllows($storeId, PlanGate::ODOO_CONNECTOR);
    }

    private static function queue(int $storeId, string $kind, int $sourceId, int $orderId, string $reference): void
    {
        $record = OdooSyncRecord::query()->firstOrCreate(
            ['store_id' => $storeId, 'kind' => $kind, 'source_id' => $sourceId],
            ['order_id' => $orderId, 'reference' => $reference, 'status' => 'pending', 'next_attempt_at' => now()],
        );
        if ($record->wasRecentlyCreated) {
            DB::afterCommit(fn () => SyncOdooRecordJob::dispatch((int) $record->id));
        }
    }

    /** Run one row now (job, scheduler or the owner's Retry). */
    public function run(int $recordId): void
    {
        Cache::lock('odoo-sync:'.$recordId, 300)->get(fn () => TenantContext::bypass(fn () => $this->attempt($recordId)));
    }

    /**
     * Rows whose next attempt is due (the scheduler dispatches them).
     *
     * @return list<int>
     */
    public static function due(int $limit = 200): array
    {
        return TenantContext::bypass(fn () => OdooSyncRecord::query()->whereIn('status', ['pending', 'retrying'])
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id')->limit($limit)->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    private function attempt(int $recordId): void
    {
        $record = OdooSyncRecord::query()->find($recordId);
        if (! $record || in_array($record->status, ['synced', 'skipped'], true)) {
            return;
        }
        if (! PlanGate::storeAllows($record->store_id, PlanGate::ODOO_CONNECTOR)) {
            $record->forceFill(['status' => 'skipped', 'last_error' => PlanGate::upsell(PlanGate::ODOO_CONNECTOR), 'next_attempt_at' => null])->save();

            return;
        }
        $conn = OdooConnection::for($record->store_id);
        if ($conn === null || ! $conn->enabled) {
            $record->forceFill(['status' => 'skipped', 'last_error' => 'The store is not connected to Odoo.', 'next_attempt_at' => null])->save();

            return;
        }

        $result = is_array($record->result) ? $record->result : [];
        try {
            $order = Order::query()->where('store_id', $record->store_id)->findOrFail($record->order_id);
            $client = new OdooClient($conn);
            if ($record->kind === 'order') {
                $this->sync->order($client, $conn, $order, $record->reference, $result);
            } else {
                $adjustment = OrderAdjustment::query()->where('order_id', $order->id)->findOrFail($record->source_id);
                $sale = OdooSyncRecord::query()->where('store_id', $record->store_id)->where('kind', 'order')->where('source_id', $order->id)->first();
                if ($sale === null) {
                    self::orderPaid($order); // a refund of a sale made before the connection: send the sale first
                    $sale = OdooSyncRecord::query()->where('store_id', $record->store_id)->where('kind', 'order')->where('source_id', $order->id)->first();
                }
                if ($sale !== null && $sale->status !== 'synced') {
                    $this->attempt((int) $sale->id);
                    $sale->refresh();
                }
                $this->sync->refund($client, $conn, $order, $adjustment, $record->reference, is_array($sale?->result) ? $sale->result : [], $result);
            }
            $record->forceFill(['status' => 'synced', 'result' => $result, 'last_error' => null, 'synced_at' => now(), 'next_attempt_at' => null,
                'attempts' => $record->attempts + 1])->save();
        } catch (\Throwable $e) {
            $attempts = $record->attempts + 1;
            $last = $attempts > count(self::BACKOFF);
            $message = $e instanceof OdooError ? $e->getMessage() : 'Unexpected error ('.class_basename($e).').';
            if (! $e instanceof OdooError) {
                report($e);
            }
            $record->forceFill([
                'status' => $last ? 'failed' : 'retrying',
                'attempts' => $attempts,
                'result' => $result, // ids made before the failure: the next attempt continues from them
                'last_error' => mb_substr($message, 0, 500),
                'next_attempt_at' => $last ? null : now()->addSeconds(self::BACKOFF[$attempts - 1]),
            ])->save();
        }
    }

    /** The owner's Retry: a failed or retrying row runs again now, with a fresh attempt budget. */
    public function retry(OdooSyncRecord $record): void
    {
        if ($record->status === 'synced') {
            return;
        }
        $record->forceFill(['status' => 'pending', 'attempts' => 0, 'next_attempt_at' => now()])->save();
        $this->run((int) $record->id);
    }
}
