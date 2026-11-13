<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Loyalty\Http\Requests\Admin;

use Juwri\Core\Http\Requests\Admin\PanelFormRequest;
use App\Models\Coupon;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Edit a coupon: limit, expiry, type and interval scope. The code and the
 * discount are fixed once issued. A new expiry must lie in the future; sending
 * back the stored expiry unchanged is always accepted.
 */
class CouponUpdateRequest extends PanelFormRequest
{
    use NormalisesCouponInterval;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expiry_at' => ['required', 'date'],
            'uses_limit' => ['required', 'integer', 'min:1'],
            'coupon_type' => ['required', Rule::in(['percentage', 'fixed', 'free_delivery', 'bogo'])],
            'applies_interval' => ['nullable', 'string'],
            'first_period_only' => ['nullable', 'boolean'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty() || ! $this->expiryChanged()) {
                return;
            }
            if ($this->date('expiry_at')?->isPast()) {
                $validator->errors()->add('expiry_at', ___('The expiry date should be in the future.'));
            }
        }];
    }

    /** @return array<string, mixed> */
    public function couponAttributes(): array
    {
        return [
            'expiry_at' => $this->expiryChanged() ? $this->date('expiry_at') : $this->coupon()->expiry_at,
            'limit' => (int) $this->validated('uses_limit'),
            'coupon_type' => $this->validated('coupon_type'),
            'applies_interval' => $this->billingInterval(),
            'first_period_only' => $this->boolean('first_period_only'),
        ];
    }

    private function expiryChanged(): bool
    {
        $stored = $this->coupon()->expiry_at;

        return $stored === null || ! Carbon::parse((string) $this->input('expiry_at'))->equalTo($stored);
    }

    private function coupon(): Coupon
    {
        $coupon = $this->route('coupon');

        return $coupon instanceof Coupon ? $coupon : new Coupon;
    }
}
