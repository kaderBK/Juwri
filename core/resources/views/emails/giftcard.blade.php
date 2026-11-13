{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<x-mail.frame :title="___('Gift card')" :brand="$restaurantName" :eyebrow="___('Gift card')" :heading="number_format((float) $card->initial_value, 2).' '.$card->currency">
    @if ($card->message)
        <p style="margin:0 0 16px;font-size:16px;">“{{ $card->message }}”</p>
    @endif
    <p class="jm-muted" style="margin:0 0 6px;font-size:13px;color:#76767F;">{{ ___('Your gift card code') }}</p>
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 8px;">
        <tr><td class="jm-ink" style="padding:12px 18px;border:2px dashed #CFCBC2;border-radius:10px;font-size:24px;font-weight:800;letter-spacing:.12em;color:#17171C;font-family:ui-monospace,Menlo,Consolas,monospace;">{{ $card->code }}</td></tr>
    </table>
    @if ($card->expires_at)
        <p class="jm-muted" style="margin:8px 0 0;font-size:13px;color:#76767F;">{{ ___('Valid until') }} {{ date_formating((string) $card->expires_at, 'M j, Y') }}</p>
    @endif
    @if ($storeUrl)
        <x-mail.button :href="$storeUrl">{{ ___('Order now') }}</x-mail.button>
    @endif

    <x-slot:footer>{{ $restaurantName }}</x-slot:footer>
</x-mail.frame>
