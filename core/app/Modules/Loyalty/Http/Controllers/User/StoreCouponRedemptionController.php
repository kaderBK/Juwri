<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Loyalty\Http\Controllers\User;

use App\Models\Coupon;
use Illuminate\Contracts\View\View;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Loyalty\Services\CouponRedemptionLog;

/**
 * Store owner › Coupons (C5): the paginated redemption log of one of the signed-in owner's own coupons. Another
 * account's coupon id is a 404 (never a peek at someone else's customers).
 */
class StoreCouponRedemptionController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Coupon $coupon, CouponRedemptionLog $log): View
    {
        abort_if((int) $coupon->user_id !== (int) auth()->id() || $coupon->user_id === null, 404);

        return view('owner.marketing.coupons.redemptions', [
            'coupon' => $coupon,
            'redemptions' => $log->paginate((int) $coupon->id, self::PER_PAGE),
            'rn' => 'stores.coupons.redemptions',
        ]);
    }
}
