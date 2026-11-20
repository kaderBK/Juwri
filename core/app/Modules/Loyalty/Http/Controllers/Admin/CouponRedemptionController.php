<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Loyalty\Http\Controllers\Admin;

use App\Models\Coupon;
use Illuminate\Contracts\View\View;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Support\Hooks;
use Juwri\Modules\Loyalty\Services\CouponRedemptionLog;

/**
 * Admin › Membership › Coupons (C5): the paginated redemption log of one coupon. The superadmin sees every coupon
 * the edit panel opens (platform, reseller- or owner-issued), with the same edition gate as the edit panel.
 */
class CouponRedemptionController extends Controller
{
    private const PER_PAGE = 20;

    public function index(Coupon $coupon, CouponRedemptionLog $log): View
    {
        $this->authorize('update', $coupon);
        abort_if(Hooks::first('admin.coupons.editable', $coupon) === true, 404);

        return view('admin.billing.coupons.redemptions', [
            'coupon' => $coupon,
            'redemptions' => $log->paginate((int) $coupon->id, self::PER_PAGE),
        ]);
    }
}
