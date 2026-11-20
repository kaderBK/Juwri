{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<x-ui.panel :title="___('Edit Coupon')" :action="route('admin.coupons.update', $coupon->id)" method="PUT" files>
    @if ($coupon->isExpiry())
        <x-ui.banner tone="danger">{{ ___('This coupon has been expired') }}</x-ui.banner>
    @endif
    {{-- Code and discount are fixed once created (the server ignores them on update). --}}
    <x-ui.field name="coupon_code" :label="___('Coupon code').' *'" :value="$coupon->code" class="ui-mono" disabled />
    <div class="ui-field ui-field--disabled">
        <label class="ui-field__label" for="coupon-discount">{{ ___('Discount') }} *</label>
        <div class="ui-input-group">
            <input id="coupon-discount" type="number" name="percentage" class="ui-input" value="{{ $coupon->percentage }}" disabled>
            <span class="ui-input-group__text">%</span>
        </div>
    </div>
    @if ($coupon->user_id === null && $coupon->reseller_id === null)
        @includeIf('billing::admin.coupons._promotion-fields', ['coupon' => $coupon])
    @endif
    @if ($coupon->user_id !== null || $coupon->reseller_id !== null || ! \Illuminate\Support\Facades\View::exists('billing::admin.coupons._promotion-fields'))
        @include('admin.billing.coupons._scope', ['coupon' => $coupon])
    @endif
    <x-ui.field type="number" name="uses_limit" :label="___('Uses Limit').' *'" :value="$coupon->limit" min="1" placeholder="0" required />
    <x-ui.field type="datetime-local" name="expiry_at" :label="___('Expiry at').' *'" :value="\Carbon\Carbon::parse($coupon->expiry_at)->format('Y-m-d\TH:i')" required />
    <x-ui.field type="select" name="coupon_type" :label="___('Coupon Type')" :value="$coupon->coupon_type"
        :options="['percentage' => ___('Percentage'), 'fixed' => ___('Fixed')]" />
    {{-- C5: the full redemption log is its own page. --}}
    <div class="ui-field">
        <x-ui.button size="sm" variant="secondary" :href="route('admin.coupons.redemptions', $coupon->id)">{{ ___('View all redemptions') }}</x-ui.button>
    </div>
</x-ui.panel>
