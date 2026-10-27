{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<x-mail.frame :title="___('Confirm your account')" :brand="$restaurantName" :eyebrow="___('Confirm your account')" :heading="$restaurantName">
    <p style="margin:0 0 8px;">
        {{ $customerName ? ___('Hi :name,', ['name' => $customerName]) : ___('Hi,') }}
    </p>
    <p style="margin:0;">
        {{ ___('This email already has previous orders. Confirm it to finish setting up your account and see your order history.') }}
    </p>
    <x-mail.button :href="$verifyUrl">{{ ___('Confirm my account') }}</x-mail.button>

    <x-slot:footer>
        {{ ___('This link expires in 48 hours. If you did not create an account, you can ignore this email.') }}<br>{{ $restaurantName }}
    </x-slot:footer>
</x-mail.frame>
