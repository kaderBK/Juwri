<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Loyalty\Services;

use App\Models\Coupon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Validates and normalises platform (admin) bulk coupon inputs before passing them to {@see CouponBulk}.
 */
class PlatformCouponInput
{
    /**
     * Validate a bulk coupon creation request and return normalised arguments for {@see CouponBulk::generate()}.
     *
     * @param  array<string, mixed>  $input
     * @return array{
     *     name: string|null,
     *     prefix: string|null,
     *     count: int,
     *     length: int,
     *     settings: array<string, mixed>
     * }
     */
    public function validateBulk(array $input): array
    {
        $couponType = ($input['coupon_type'] ?? 'percentage') === 'fixed' ? 'fixed' : 'percentage';
        $amountRules = ['required', ...Coupon::valueRules($couponType, platform_currency()), 'min:1'];

        $validated = Validator::make($input, [
            'name' => ['nullable', 'string', 'max:120'],
            'prefix' => ['nullable', 'string', 'max:12', 'regex:/^[A-Za-z0-9]+$/'],
            'count' => ['required', 'integer', 'min:1', 'max:'.CouponBulk::MAX_COUNT],
            'length' => ['nullable', 'integer', 'min:6', 'max:12'],
            'coupon_type' => ['nullable', Rule::in(['percentage', 'fixed'])],
            'discount_percentage' => $amountRules,
            'uses_limit' => ['required', 'integer', 'min:0'],
            'expiry_at' => ['nullable', 'date', 'after:now'],
            'applies_interval' => ['nullable', 'string'],
            'first_period_only' => ['nullable', 'boolean'],
        ], [
            'prefix.regex' => ___('Prefix must contain only letters and numbers.'),
            'expiry_at.after' => ___('The expiry date should be in the future.'),
        ])->validate();

        $prefix = isset($validated['prefix']) && trim((string) $validated['prefix']) !== ''
            ? strtoupper(trim((string) $validated['prefix']))
            : null;
        $name = isset($validated['name']) && trim((string) $validated['name']) !== ''
            ? trim((string) $validated['name'])
            : null;

        $settings = [
            'coupon_type' => $couponType,
            'percentage' => $validated['discount_percentage'],
            'limit' => (int) $validated['uses_limit'],
            'applies_interval' => $this->normaliseInterval($validated['applies_interval'] ?? null),
            'first_period_only' => ! empty($validated['first_period_only']),
        ];

        if (! empty($validated['expiry_at'])) {
            $settings['expiry_at'] = (string) $validated['expiry_at'];
        }

        return [
            'name' => $name,
            'prefix' => $prefix,
            'count' => (int) $validated['count'],
            'length' => (int) ($validated['length'] ?? 8),
            'settings' => $settings,
        ];
    }

    private function normaliseInterval(?string $interval): string
    {
        return in_array($interval, ['monthly', 'yearly', 'lifetime'], true) ? $interval : 'any';
    }
}
