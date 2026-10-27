{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<x-mail.frame :title="___('Your account has been deleted')" :brand="$storeName" :eyebrow="___('Your account has been deleted')" :heading="$storeName">
    <p style="margin:0 0 8px;">
        {{ $customerName ? ___('Hi :name,', ['name' => $customerName]) : ___('Hi,') }}
    </p>
    <p style="margin:0 0 8px;">
        {{ ___('Your account with this store has been deleted. Your profile, addresses, reviews, loyalty points and saved items are gone, and you have been signed out everywhere.') }}
    </p>
    <p style="margin:0;">
        {{ ___('The store keeps its sales and tax records of your past orders as the law requires, without your name, phone number or address.') }}
    </p>

    <x-slot:footer>
        {{ ___('Accounts you have with other stores are not affected. If you did not ask for this, contact the store.') }}<br>{{ $storeName }}
    </x-slot:footer>
</x-mail.frame>
