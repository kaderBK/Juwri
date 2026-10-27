{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- P3-08 / N5: back-in-stock message (sent once, only with the shopper's marketing opt-in). --}}
<x-mail.frame :title="___('Back in stock')" :brand="$storeName" :eyebrow="___('Back in stock')" :heading="$itemName">
    <p style="margin:0 0 16px;font-size:16px;">{{ ___(':item is back in stock', ['item' => $itemName]) }}</p>
    <p class="jm-muted" style="margin:0 0 6px;font-size:13px;color:#76767F;">{{ ___('You asked us to tell you. Stock can sell out again quickly.') }}</p>
    <x-mail.button :href="$itemUrl">{{ ___('Shop now') }}</x-mail.button>

    <x-slot:footer>{{ $storeName }}</x-slot:footer>
</x-mail.frame>
