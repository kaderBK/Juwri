{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Interval scope + first-period flag, shared by the coupon panels (P4). $coupon is null on create. --}}
<x-ui.field type="select" name="applies_interval" :label="___('Applies to billing interval')" :value="$coupon->applies_interval ?? 'any'"
    :options="['any' => ___('Any'), 'monthly' => ___('Monthly'), 'yearly' => ___('Yearly'), 'lifetime' => ___('Lifetime')]" />
<x-ui.check name="first_period_only" value="1" :checked="(bool) old('first_period_only', $coupon->first_period_only ?? false)" :label="___('First period only (recurring subscriptions)')" />
