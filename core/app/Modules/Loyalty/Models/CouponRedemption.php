<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Loyalty\Models;

use Illuminate\Database\Eloquent\Model;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use App\Models\Order;

/**
 * M3: append-only record of one customer's use of one coupon. The unique
 * (coupon_id, customer_id, seq) is what enforces `coupons.per_customer_limit`
 * atomically; see CouponService::claimForCustomer().
 */
class CouponRedemption extends Model
{
    use BelongsToAccount;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'coupon_id' => 'integer',
        'customer_id' => 'integer',
        'seq' => 'integer',
        'order_id' => 'integer',
        'created_at' => 'datetime',
    ];

    /**
     * How many times this customer has already redeemed this coupon.
     *
     * The union of two sources, by max() and NOT by sum():
     *   · rows in this table — every redemption from M3 onward;
     *   · orders carrying (coupon_id, customer_id) — how resolve() has ALWAYS
     *     counted, and the only record of redemptions that predate this table.
     * Every redemption from now on writes BOTH, so adding them would double-count;
     * max() is correct in the mixed case (2 legacy orders + 1 new redemption is
     * 3 orders and 1 row -> 3). Dropping the legacy leg instead would hand a fresh
     * allowance to every customer who already redeemed a coupon on the day a
     * merchant first sets a per-customer limit on it.
     */
    public static function countFor(int $couponId, int $customerId): int
    {
        $rows = static::where('coupon_id', $couponId)
            ->where('customer_id', $customerId)
            ->count();

        $legacy = Order::where('coupon_id', $couponId)
            ->where('customer_id', $customerId)
            ->count();

        return max($rows, $legacy);
    }
}
