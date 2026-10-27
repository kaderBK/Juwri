{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<x-mail.frame :title="___('Loyalty points update for order #:order', ['order' => $orderId])" :brand="$storeName" :eyebrow="___('Loyalty points update for order #:order', ['order' => $orderId])" :heading="$storeName">
    <p style="margin:0 0 8px;">
        {{ $customerName ? ___('Hi :name,', ['name' => $customerName]) : ___('Hi,') }}
    </p>
    <p style="margin:0;">
        {{ $text }}
    </p>
</x-mail.frame>
