<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Loyalty\Services;

use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Order;
use ArrayObject;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Juwri\Core\Models\User;
use Juwri\Core\Support\Hooks;
use Juwri\Modules\Loyalty\Contracts\CouponRedemptionLog as CouponRedemptionLogContract;
use Juwri\Modules\Loyalty\Models\CouponRedemption;
use Juwri\Modules\Payments\Models\Transaction;

/**
 * Coupons C5: read-only redemption log for one coupon or a batch of coupons.
 *
 * Nothing new is stored: every row is derived from what each surface already records when a coupon is used.
 *
 *   store_order / pos   orders.coupon_id (+ discount_amount_minor), and coupon_redemptions (per-customer slots)
 *                       whose order is gone or was never recorded
 *   plan_checkout       a successful transaction whose `coupon` snapshot names the coupon (platform coupons)
 *   reseller_checkout   the same for a reseller-issued coupon, plus whatever the edition adds through
 *                       {@see self::EXTRA_ROWS_SLOT} (the Reseller module: subscriptions carrying coupon_id)
 *
 * Ownership is NOT decided here: callers load the coupon(s) through their own scope (admin / reseller_id / user_id)
 * and pass only the ids they may see.
 *
 * Row shape: when (Carbon|null), coupon_id, code, who_name, who_id, who_type (customer|user), ref_type
 * (order|transaction|subscription), ref_id, saved_minor (int|null: null = not recorded by that surface),
 * currency (string|null), surface.
 */
class CouponRedemptionLog implements CouponRedemptionLogContract
{
    /**
     * @param  int|array<int, int>  $couponIds
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(int|array $couponIds, int $perPage = 20, ?int $page = null): LengthAwarePaginator
    {
        $rows = $this->rows($couponIds);
        $page ??= max(1, (int) request()->query('page', 1));

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->except('page')]
        );
    }

    /**
     * Every redemption, newest first.
     *
     * @param  int|array<int, int>  $couponIds
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(int|array $couponIds): Collection
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $couponIds), fn (int $id) => $id > 0)));
        if ($ids === []) {
            return collect();
        }

        /** @var Collection<int, Coupon> $coupons */
        $coupons = Coupon::query()->whereIn('id', $ids)->get()->keyBy('id');

        /** @var ArrayObject<int, array<string, mixed>> $extra */
        $extra = new ArrayObject;
        Hooks::run(self::EXTRA_ROWS_SLOT, $ids, $coupons, $extra);

        return $this->orderRows($ids, $coupons)
            ->concat($this->transactionRows($ids, $coupons))
            ->concat(array_values(array_filter($extra->getArrayCopy(), 'is_array')))
            ->sortByDesc(fn (array $r) => $r['when']?->getTimestamp() ?? 0)
            ->values();
    }

    /**
     * @param  array<int, int>  $ids
     * @param  Collection<int, Coupon>  $coupons
     * @return Collection<int, array<string, mixed>>
     */
    private function orderRows(array $ids, Collection $coupons): Collection
    {
        $orders = Order::query()->whereIn('coupon_id', $ids)->get();
        $orderIds = $orders->pluck('id')->map(fn ($i) => (int) $i)->all();

        $slots = CouponRedemption::query()->whereIn('coupon_id', $ids)->get();
        $slotOrderIds = $slots->pluck('order_id')->filter()->map(fn ($i) => (int) $i)->unique()->values()->all();
        // Orders a slot points at that were not loaded above (another coupon on the order now, or gone).
        $existingSlotOrders = $slotOrderIds === [] ? [] : Order::query()->whereIn('id', $slotOrderIds)->pluck('id')->map(fn ($i) => (int) $i)->all();
        $slots = $slots->reject(fn (CouponRedemption $r) => $r->order_id
            && (in_array((int) $r->order_id, $orderIds, true) || in_array((int) $r->order_id, $existingSlotOrders, true)));

        $customerIds = $orders->pluck('customer_id')->merge($slots->pluck('customer_id'))->filter()->unique()->values()->all();
        $customers = $customerIds === [] ? collect() : Customer::query()->whereIn('id', $customerIds)->get(['id', 'name'])->keyBy('id');

        /** @var array<int, array<string, mixed>> $out */
        $out = [];
        foreach ($orders as $o) {
            if (! empty($o->cancelled_at)) {
                continue; // a cancelled order released its coupon slot
            }
            $cid = (int) $o->coupon_id;
            $out[] = [
                'when' => $o->created_at,
                'coupon_id' => $cid,
                'code' => (string) ($coupons[$cid]->code ?? ''),
                'who_name' => $customers[$o->customer_id]->name ?? ($o->getAttribute('customer_name') ?: null),
                'who_id' => $o->customer_id ? (int) $o->customer_id : null,
                'who_type' => 'customer',
                'ref_type' => 'order',
                'ref_id' => (int) $o->id,
                'saved_minor' => (int) $o->discount_amount_minor,
                'currency' => $o->currency_code ?: null,
                'surface' => (string) $o->getAttribute('source') === 'pos' ? self::SURFACE_POS : self::SURFACE_STORE_ORDER,
            ];
        }

        // Slot rows whose order is gone (or was never recorded): still a redemption.
        foreach ($slots as $r) {
            $cid = (int) $r->coupon_id;
            $out[] = [
                'when' => $r->created_at,
                'coupon_id' => $cid,
                'code' => (string) ($coupons[$cid]->code ?? ''),
                'who_name' => $customers[$r->customer_id]->name ?? null,
                'who_id' => (int) $r->customer_id,
                'who_type' => 'customer',
                'ref_type' => 'order',
                'ref_id' => $r->order_id ? (int) $r->order_id : null,
                'saved_minor' => null,
                'currency' => null,
                'surface' => self::SURFACE_STORE_ORDER,
            ];
        }

        return collect($out);
    }

    /**
     * @param  array<int, int>  $ids
     * @param  Collection<int, Coupon>  $coupons
     * @return Collection<int, array<string, mixed>>
     */
    private function transactionRows(array $ids, Collection $coupons): Collection
    {
        $txs = collect();
        foreach (array_chunk($ids, 100) as $chunk) {
            $txs = $txs->concat(Transaction::query()
                ->where('status', Transaction::STATUS_SUCCESS)
                ->whereNotNull('coupon')
                ->whereIn('coupon->id', array_merge($chunk, array_map('strval', $chunk)))
                ->get());
        }
        if ($txs->isEmpty()) {
            return collect();
        }

        $userIds = $txs->pluck('user_id')->filter()->unique()->values()->all();
        $users = $userIds === [] ? collect() : User::query()->whereIn('id', $userIds)->get(['id', 'name'])->keyBy('id');

        /** @var array<int, array<string, mixed>> $out */
        $out = [];
        foreach ($txs->unique('id') as $t) {
            $snap = (array) $t->coupon;
            $cid = (int) ($snap['id'] ?? 0);
            if (! in_array($cid, $ids, true)) {
                continue;
            }
            $coupon = $coupons[$cid] ?? null;

            $out[] = [
                'when' => $t->created_at,
                'coupon_id' => $cid,
                'code' => (string) ($coupon->code ?? ($snap['code'] ?? '')),
                'who_name' => $users[$t->user_id]->name ?? null,
                'who_id' => $t->user_id ? (int) $t->user_id : null,
                'who_type' => 'user',
                'ref_type' => 'transaction',
                'ref_id' => (int) $t->id,
                'saved_minor' => $this->savedMinor($t, $snap, $coupon),
                'currency' => $t->currency_code ?: null,
                'surface' => $coupon !== null && ! empty($coupon->reseller_id) ? self::SURFACE_RESELLER : self::SURFACE_PLAN,
            ];
        }

        return collect($out);
    }

    /**
     * The discount of one plan payment in the payment's minor units. A percentage coupon is applied to the base
     * amount (half-up on the thousandths of a percent, integer arithmetic); a fixed coupon is its own amount; never
     * more than the base amount. Null when the payment records no base amount.
     *
     * @param  array<string, mixed>  $snap
     */
    private function savedMinor(Transaction $t, array $snap, ?Coupon $coupon): ?int
    {
        $base = $t->base_amount_minor !== null ? (int) $t->base_amount_minor : null;
        if ($base === null || $base <= 0) {
            return null;
        }

        $type = (string) ($snap['coupon_type'] ?? ($coupon->coupon_type ?? 'percentage'));
        if ($type === 'fixed') {
            $amount = $coupon?->amount_minor;

            return $amount !== null ? min((int) $amount, $base) : null;
        }

        $rate = (string) ($snap['percentage'] ?? ($coupon->percentage_rate ?? '0'));
        $milli = (int) round(max(0.0, min(100.0, (float) $rate)) * 1000); // a percent, not money

        return min($base, intdiv($base * $milli + 50000, 100000));
    }
}
