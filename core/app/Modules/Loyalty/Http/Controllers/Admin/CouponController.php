<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Loyalty\Http\Controllers\Admin;

use App\Models\Coupon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Http\Controllers\Admin\Concerns\AnswersAdminPanel;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Http\Requests\Admin\BulkDeleteRequest;
use Juwri\Core\Money\Currencies;
use Juwri\Core\Support\Admin\Cells;
use Juwri\Core\Support\Admin\DataTable;
use Juwri\Core\Support\Hooks;
use Juwri\Modules\Loyalty\Http\Requests\Admin\CouponStoreRequest;
use Juwri\Modules\Loyalty\Http\Requests\Admin\CouponUpdateRequest;

/**
 * Admin › Membership › Coupons: discount codes for plan purchases. Codes are
 * issued once (code and discount fixed); limit, expiry, type and billing
 * interval can be edited later. Removal is through the bulk action only.
 */
class CouponController extends Controller
{
    use AnswersAdminPanel;

    public function __construct()
    {
        $this->authorizeResource(Coupon::class, 'coupon');
    }

    public function index(Request $request): View|JsonResponse
    {
        if (! $request->ajax()) {
            return view('admin.billing.coupons.index');
        }

        $sortable = [0 => 'id', 1 => 'code', 2 => 'percentage_rate', 3 => 'used', 4 => 'limit', 5 => 'expiry_at', 6 => 'coupon_type'];
        $query = Coupon::query();
        Hooks::call('admin.coupons.query', $query);

        return DataTable::make($request, $sortable, ['id', 'code'])
            ->respond($query, fn (Coupon $coupon) => [
                Cells::text((string) $coupon->id),
                Cells::html('<strong>'.e($coupon->code).'</strong>'),
                // A fixed coupon is an amount of its currency (was shown as "5%").
                Cells::text(Coupon::typeIsAmount($coupon->coupon_type)
                    ? number_format((float) $coupon->percentage, Currencies::exponent($coupon->minorMoneyCurrency()), '.', '').' '.$coupon->minorMoneyCurrency()
                    : $coupon->percentage.'%'),
                Cells::text((string) $coupon->used),
                Cells::text((string) $coupon->limit),
                $coupon->isExpiry()
                    ? Cells::html(Cells::badge(___('Expired'), 'danger'))
                    : Cells::text(date_formating($coupon->expiry_at)),
                Cells::text($coupon->coupon_type),
                Cells::editButton(route('admin.coupons.edit', $coupon->id)),
                Cells::checkbox($coupon->id),
            ]);
    }

    public function create(): View
    {
        return view('admin.billing.coupons.create');
    }

    public function store(CouponStoreRequest $request): JsonResponse
    {
        DB::transaction(function () use ($request): void {
            $coupon = Coupon::create($request->couponAttributes());
            Hooks::call('admin.coupons.saved', $coupon, $request);
        });

        return $this->panelDone(___('Created Successfully'));
    }

    public function show(Coupon $coupon): never
    {
        abort(404);
    }

    public function edit(Coupon $coupon): View
    {
        abort_if(Hooks::first('admin.coupons.editable', $coupon) === true, 404);

        return view('admin.billing.coupons.edit', ['coupon' => $coupon]);
    }

    public function update(CouponUpdateRequest $request, Coupon $coupon): JsonResponse
    {
        abort_if(Hooks::first('admin.coupons.editable', $coupon) === true, 404);

        DB::transaction(function () use ($request, $coupon): void {
            $coupon->update($request->couponAttributes());
            Hooks::call('admin.coupons.saved', $coupon, $request);
        });

        return $this->panelDone(___('Updated Successfully'));
    }

    public function destroy(Coupon $coupon): never
    {
        abort(404);
    }

    public function delete(BulkDeleteRequest $request): JsonResponse
    {
        $this->authorize('deleteAny', Coupon::class);

        $ids = $request->ids();
        if (Hooks::has('admin.coupons.editable')) {
            foreach (Coupon::query()->whereKey($ids)->get() as $coupon) {
                abort_if(Hooks::first('admin.coupons.editable', $coupon) === true, 404);
            }
        }

        Coupon::query()->whereKey($ids)->delete();

        return $this->panelDone(___('Deleted Successfully'));
    }
}
