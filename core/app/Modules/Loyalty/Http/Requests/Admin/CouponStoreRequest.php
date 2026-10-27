<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Loyalty\Http\Requests\Admin;

use App\Models\Coupon;
use Illuminate\Validation\Rule;
use Juwri\Core\Http\Requests\Admin\PanelFormRequest;

/**
 * New platform (plan) coupon. `discount_percentage` carries the discount:
 * a percentage (1–100, up to 3 decimals) or, for a fixed coupon, a flat amount
 * (≥ 1, in the platform currency's decimals), written to the coupon's
 * `percentage` attribute that checkout reads ({@see Coupon}).
 */
class CouponStoreRequest extends PanelFormRequest
{
    use NormalisesCouponInterval;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // A platform coupon's currency is the platform's ({@see Coupon::defaultMinorMoneyCurrency()}).
        $amount = ['required', ...Coupon::valueRules($this->couponType(), platform_currency()), 'min:1'];

        return [
            'code' => ['required', 'string', 'min:3', 'max:20', 'alpha_num:ascii', Coupon::uniqueCodeRule(null, null)] /* P6-215: platform scope */,
            'coupon_type' => ['nullable', Rule::in(['percentage', 'fixed'])],
            'discount_percentage' => $amount,
            'expiry_at' => ['required', 'date', 'after:now'],
            'uses_limit' => ['required', 'integer', 'min:1'],
            'applies_interval' => ['nullable', 'string'],
            'first_period_only' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['expiry_at.after' => ___('The expiry date should be in the future.')];
    }

    public function couponType(): string
    {
        return $this->input('coupon_type') === 'fixed' ? 'fixed' : 'percentage';
    }

    /** @return array<string, mixed> */
    public function couponAttributes(): array
    {
        return [
            'code' => $this->validated('code'),
            'coupon_type' => $this->couponType(),
            'percentage' => $this->validated('discount_percentage'),
            'expiry_at' => $this->date('expiry_at'),
            'limit' => (int) $this->validated('uses_limit'),
            'applies_interval' => $this->billingInterval(),
            'first_period_only' => $this->boolean('first_period_only'),
        ];
    }
}
