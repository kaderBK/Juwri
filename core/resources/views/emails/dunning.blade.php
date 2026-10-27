{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<x-mail.frame :title="___('Payment failed')" :heading="___('Payment failed')">
    <p style="margin:0 0 12px;">{{ ___('Hi :name,', ['name' => $name]) }}</p>
    <p style="margin:0 0 12px;">{{ ___("We couldn't process the payment for your subscription. To avoid any interruption to your service, please update your payment method.") }}</p>
    @if ($graceUntil)
        <p style="margin:0;">{!! ___('Your service stays active until :date.', ['date' => '<strong>'.e($graceUntil).'</strong>']) !!}</p>
    @endif
    <x-mail.button :href="$billingUrl">{{ ___('Update payment method') }}</x-mail.button>

    <x-slot:footer>
        {{ ___("If you've already updated it, you can ignore this message.") }}<br>{{ config('app.name') }}
    </x-slot:footer>
</x-mail.frame>
