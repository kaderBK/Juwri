{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<x-ui.panel :title="___('Edit').' '.$gateway->payment_folder" :action="route('admin.gateways.update', $gateway->id)">
    <x-ui.switch name="active" off="0" :checked="(bool) $gateway->payment_install" :label="___('Active')" />
    <x-ui.field name="title" :label="___('Title').' *'" :value="$gateway->payment_title" required />
    <p class="ui-section-title">{{ ___('Credentials') }}</p>
    @include('admin.billing.gateways.forms.'.$gateway->payment_folder)
</x-ui.panel>
