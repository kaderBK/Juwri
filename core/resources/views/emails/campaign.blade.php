{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Marketing campaign: $body is the owner's own HTML (allowlist-sanitised by CampaignHtml on save and again at send, P6-286). --}}
<x-mail.frame :title="$subject">
    {!! $body !!}

    <x-slot:footer>
        {{ ___('You are receiving this email because you opted in to receive updates from our restaurant.') }}<br>
        <a href="{{ $unsubscribeUrl }}" target="_blank" rel="noopener" class="jm-muted" style="color:#76767F;text-decoration:underline;">{{ ___('Unsubscribe from future emails') }}</a>
    </x-slot:footer>
</x-mail.frame>
