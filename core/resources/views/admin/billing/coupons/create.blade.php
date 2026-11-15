{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<x-ui.panel :title="___('Add Coupon')" :action="route('admin.coupons.store')" files>
    <div class="ui-field">
        <label class="ui-field__label" for="couponCodeInput">{{ ___('Coupon code') }} *</label>
        <div class="ui-input-group">
            <input id="couponCodeInput" type="text" name="code" class="ui-input ui-mono" placeholder="{{ ___('Coupon code') }}" maxlength="20" required data-ui-code>
            <button type="button" class="ui-btn ui-btn--secondary" data-ui-code-for="couponCodeInput">
                <span class="ui-btn__icon"><x-ui.icon name="refresh" size="16" /></span>{{ ___('Generate') }}
            </button>
        </div>
    </div>
    <div class="ui-field">
        <label class="ui-field__label" for="coupon-discount">{{ ___('Discount') }} *</label>
        <div class="ui-input-group">
            <input id="coupon-discount" type="number" name="discount_percentage" class="ui-input" min="1" max="100" step="0.001" placeholder="0" required>
            <span class="ui-input-group__text">%</span>
        </div>
    </div>
    @includeIf('billing::admin.coupons._promotion-fields', ['coupon' => null])
    @if (! \Illuminate\Support\Facades\View::exists('billing::admin.coupons._promotion-fields'))
        @include('admin.billing.coupons._scope', ['coupon' => null])
    @endif
    <x-ui.field type="number" name="uses_limit" :label="___('Uses Limit').' *'" value="1" min="1" placeholder="0" required />
    <x-ui.field type="datetime-local" name="expiry_at" :label="___('Expiry at').' *'" required />
</x-ui.panel>
