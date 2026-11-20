{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<section id="quick_billing" role="tabpanel" aria-labelledby="tab-quick_billing" @if (($active ?? true) === false) hidden @endif>
    <form method="POST" action="{{ route('admin.settings.update') }}" data-ui-ajax>
        <x-ui.card :title="___('Billing details')">
            <div class="ui-stack">
                {{-- WI-06b (owner decision): platform payments (plans, add-ons) refund through the gateway only when switched on. --}}
                <x-ui.switch name="auto_refunds_enabled" off="0" :checked="($settings->auto_refunds_enabled ?? '0') === '1'"
                             :label="___('Refund platform payments automatically')"
                             :description="___('When a platform payment is refunded, send the money back through the gateway that took it (Stripe, PayPal, Razorpay, Paystack). Off: refunds are tracked and completed in the gateway dashboard.')" />
                {{-- Owner 11-20 (R12b SHOULD 6): the reseller platform fee when its invoicing stops mid-month. --}}
                <x-ui.field type="select" name="reseller_platform_fee_part_period" :label="___('Reseller platform fee for a part month')"
                            :value="($settings->reseller_platform_fee_part_period ?? '') === 'full' ? 'full' : 'prorate'"
                            :options="['prorate' => ___('Pro rata (days used)'), 'full' => ___('Full fee')]"
                            :help="___('When a reseller cancels and its invoicing stops in the middle of a month, charge the monthly platform fee for the days used only, or the full fee. Per-store pricing only.')" />
                <x-ui.banner tone="info">{{ ___('These details will be used for the invoice.') }}</x-ui.banner>
                <div class="ui-form-grid">
                    <x-ui.field name="invoice_nr_prefix" :label="___('Invoice Number Prefix')" :value="$settings->invoice_nr_prefix ?? 'INV-'" placeholder="{{ ___('Ex: INV-') }}" />
                    <x-ui.field name="invoice_admin_name" :label="___('Name')" :value="$settings->invoice_admin_name ?? null" />
                    <x-ui.field type="email" name="invoice_admin_email" :label="___('Email')" :value="$settings->invoice_admin_email ?? null" />
                    <x-ui.field type="tel" name="invoice_admin_phone" :label="___('Phone')" :value="$settings->invoice_admin_phone ?? null" />
                    <x-ui.field name="invoice_admin_address" :label="___('Address')" :value="$settings->invoice_admin_address ?? null" />
                    <x-ui.field name="invoice_admin_city" :label="___('City')" :value="$settings->invoice_admin_city ?? null" />
                    <x-ui.field name="invoice_admin_state" :label="___('State/Province')" :value="$settings->invoice_admin_state ?? null" />
                    <x-ui.field name="invoice_admin_zipcode" :label="___('ZIP Code')" :value="$settings->invoice_admin_zipcode ?? null" />
                    <x-ui.field type="select" name="invoice_admin_country" :label="___('Country')" :value="$settings->invoice_admin_country ?? ''"
                                :options="['' => ___('Choose')] + collect(countries())->pluck('name', 'name')->all()" />
                    <x-ui.field name="invoice_admin_tax_type" :label="___('Tax Type')" :value="$settings->invoice_admin_tax_type ?? null" placeholder="{{ ___('Ex: VAT') }}" />
                    <x-ui.field name="invoice_admin_tax_id" :label="___('Tax ID')" :value="$settings->invoice_admin_tax_id ?? null" />
                </div>
            </div>
            @include('admin.settings._save', ['marker' => 'billing_settings'])
        </x-ui.card>
    </form>
</section>
