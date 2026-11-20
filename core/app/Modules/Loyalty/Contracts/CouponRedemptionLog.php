<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Loyalty\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Public contract for the read-only coupon redemption log.
 */
interface CouponRedemptionLog
{
    public const SURFACE_STORE_ORDER = 'store_order';

    public const SURFACE_POS = 'pos';

    public const SURFACE_PLAN = 'plan_checkout';

    public const SURFACE_RESELLER = 'reseller_checkout';

    /**
     * Edition slot: `fn (array $couponIds, Collection $coupons, ArrayObject $rows): void` appends rows
     * for redemptions that only an edition module records. Best-effort (a failing hook adds nothing).
     */
    public const EXTRA_ROWS_SLOT = 'loyalty.coupon-redemption-rows';

    /**
     * @param  int|array<int, int>  $couponIds
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(int|array $couponIds, int $perPage = 20, ?int $page = null): LengthAwarePaginator;

    /**
     * Every redemption, newest first.
     *
     * @param  int|array<int, int>  $couponIds
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(int|array $couponIds): Collection;
}
