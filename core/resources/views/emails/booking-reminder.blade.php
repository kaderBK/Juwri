{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<x-mail.frame :title="___('Reminder: your booking')" :brand="$storeName" :eyebrow="___('Reminder: your booking')" :heading="$storeName">
    <p style="margin:0 0 8px;">{{ $customerName ? ___('Hi :name,', ['name' => $customerName]) : ___('Hi,') }}</p>
    <p style="margin:0 0 8px;">{{ ___('See you soon: :services on :when.', ['services' => $services, 'when' => $when]) }}</p>
    <x-mail.button :href="$manageUrl">{{ ___('View or change booking') }}</x-mail.button>
    <x-slot:footer>{{ $storeName }}</x-slot:footer>
</x-mail.frame>
