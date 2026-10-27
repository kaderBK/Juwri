<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pos\Services\Offline;

use App\Models\Order;
use App\Models\StaffMember;
use App\Services\PosService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Juwri\Core\Models\Store;
use Juwri\Modules\Payments\Contracts\PaymentTerminal;
use Juwri\Modules\Pos\Models\PosOfflineCardPayment;

/**
 * Offline card payments after the till reconnects (OFFLINE-POS-SPEC §7). The provider's status is authoritative:
 *  - authorised / captured → `captured`, and only now the order is settled as a card sale (PosService::settle: paid,
 *    shift, loyalty, ledger);
 *  - declined → `declined_after_offline`: the sale stays recorded, unpaid, as a manager exception (the store carries
 *    the loss, owner decision);
 *  - pending / unknown → unchanged, asked again later.
 * A manager resolves a declined one by collecting the money another way (cash / card): the order is settled with that
 * method and the record becomes `resolved` — the declined decision is kept on it, never deleted.
 * Each step locks the row and checks its state, so a repeated sync or a double click cannot settle twice.
 */
final class OfflineCardSettlement
{
    public function __construct(private readonly PaymentTerminal $terminal) {}

    /** @return array{captured: int, declined: int, pending: int} */
    public function syncStore(Store $store): array
    {
        $out = ['captured' => 0, 'declined' => 0, 'pending' => 0];
        foreach (PosOfflineCardPayment::query()->where('store_id', $store->id)->where('state', 'pending_offline')->orderBy('id')->pluck('id') as $id) {
            $out[$this->refresh($store, (int) $id)]++;
        }

        return $out;
    }

    /** @return 'captured'|'declined'|'pending' */
    public function refresh(Store $store, int $paymentId): string
    {
        $peek = PosOfflineCardPayment::query()->where('store_id', $store->id)->find($paymentId);
        if ($peek === null || $peek->state !== 'pending_offline') {
            return 'pending';
        }
        $answer = $this->terminal->offlineStatus($store, (string) $peek->provider_reference);
        $state = $answer['state'];
        if (! in_array($state, [PaymentTerminal::AUTHORISED, PaymentTerminal::CAPTURED, PaymentTerminal::DECLINED], true)) {
            return 'pending';
        }

        return DB::transaction(function () use ($store, $paymentId, $answer, $state) {
            $payment = PosOfflineCardPayment::query()->where('store_id', $store->id)->lockForUpdate()->find($paymentId);
            if ($payment === null || $payment->state !== 'pending_offline') {
                return 'pending';
            }
            $payment->fill(['masked_pan' => isset($answer['masked_pan']) ? substr((string) $answer['masked_pan'], 0, 32) : null,
                'scheme' => isset($answer['scheme']) ? substr((string) $answer['scheme'], 0, 24) : null, 'decided_at' => now()]);
            if ($state === PaymentTerminal::DECLINED) {
                $payment->state = 'declined_after_offline';
                $payment->save();

                return 'declined';
            }
            $payment->state = 'captured';
            $payment->save();
            $this->settle($store, $payment, 'card');

            return 'captured';
        });
    }

    /** Manager: collected another way after a decline. */
    public function resolve(Store $store, int $paymentId, string $method, ?StaffMember $by): PosOfflineCardPayment
    {
        if (! in_array($method, ['cash', 'card'], true)) {
            throw new InvalidArgumentException('method must be cash or card');
        }

        return DB::transaction(function () use ($store, $paymentId, $method, $by) {
            $payment = PosOfflineCardPayment::query()->where('store_id', $store->id)->lockForUpdate()->findOrFail($paymentId);
            if ($payment->state === 'resolved') {
                return $payment; // a repeated click: already done
            }
            if ($payment->state !== 'declined_after_offline') {
                throw new InvalidArgumentException('only a card declined after an offline sale can be resolved');
            }
            $payment->fill(['state' => 'resolved', 'resolution_method' => $method, 'resolved_by_staff_id' => $by?->id, 'resolved_at' => now()])->save();
            $this->settle($store, $payment, $method);

            return $payment;
        });
    }

    private function settle(Store $store, PosOfflineCardPayment $payment, string $method): void
    {
        $order = Order::query()->where('store_id', $store->id)->lockForUpdate()->find($payment->order_id);
        if ($order === null || $order->is_paid || $order->status === 'cancelled') {
            return;
        }
        $shift = $order->pos_shift_id;
        $settled = PosService::settle($order, $method);
        if ($shift !== null && (int) $settled->pos_shift_id !== (int) $shift) {
            $settled->forceFill(['pos_shift_id' => $shift])->save(); // stays on the shift it was sold in
        }
    }
}
