{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- call block: click-to-call + WhatsApp + SMS (bio-t15 A2, SPEC §2).
     config: {phone?, whatsapp?, sms?, label?, show:{call?,whatsapp?,sms?}}. Up to 3
     buttons; an empty channel never renders a button. Arabic-Indic/Persian digits are
     normalised before building tel:/wa.me/sms: links (whatsapp_latin_digits — the
     WA-E3 fix: a bare \D strip silently deletes ٠-٩ otherwise). --}}
@php
    $b = is_array($block ?? null) ? $block : [];
    $cfg = is_array($b['config'] ?? null) ? $b['config'] : [];
    $show = is_array($cfg['show'] ?? null) ? $cfg['show'] : [];
    $kicker = trim((string) ($b['label'] ?? $cfg['label'] ?? ''));
    $id = $b['id'] ?? '';

    $buttons = [];

    $phone = trim((string) ($cfg['phone'] ?? ''));
    if ($phone !== '' && (($show['call'] ?? true))) {
        $buttons[] = ['key' => 'call', 'href' => 'tel:'.\Juwri\Core\Support\ViewHelpers::phoneDigits($phone), 'label' => ___('Call')];
    }

    $wa = trim((string) ($cfg['whatsapp'] ?? ''));
    if ($wa !== '' && (($show['whatsapp'] ?? true))) {
        $buttons[] = ['key' => 'whatsapp', 'href' => whatsapp_link(whatsapp_normalize_number($wa)), 'label' => ___('WhatsApp')];
    }

    $sms = trim((string) ($cfg['sms'] ?? ''));
    if ($sms !== '' && (($show['sms'] ?? true))) {
        $buttons[] = ['key' => 'sms', 'href' => 'sms:'.\Juwri\Core\Support\ViewHelpers::phoneDigits($sms), 'label' => ___('Text')];
    }

    $icons = [
        'call' => 'M6.6 10.8c1.4 2.8 3.8 5.1 6.6 6.6l2.2-2.2c.3-.3.7-.4 1-.2 1.1.4 2.3.6 3.6.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1C10.6 21 3 13.4 3 4c0-.6.4-1 1-1h3.5c.6 0 1 .4 1 1 0 1.2.2 2.4.6 3.6.1.3 0 .7-.2 1l-2.3 2.2z',
        'whatsapp' => 'M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm5.4 14.1c-.2.7-1.3 1.3-1.8 1.3-.5.1-1 .2-3.3-.7-2.8-1.1-4.6-4-4.7-4.2-.1-.2-1.1-1.5-1.1-2.9s.7-2 1-2.3c.2-.3.5-.3.7-.3h.5c.2 0 .4 0 .6.4l.9 2.1c.1.2.1.4 0 .6l-.4.6-.5.5c-.2.2-.3.4-.1.7.2.3.8 1.4 1.8 2.2 1.2 1.1 2.3 1.4 2.6 1.6.3.1.5.1.7-.1l1-1.2c.2-.3.4-.2.7-.1l2 1c.3.1.5.2.6.4 0 .1 0 .7-.2 1.4z',
        'sms' => 'M4 4h16a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H8l-4.5 4V6a1 1 0 0 1 1-1Z',
    ];
@endphp
@if (! empty($buttons))
    <div class="bio-call-row" @if ($id !== '') data-bio-block="{{ $id }}" @endif>
        @if ($kicker !== '')<p class="bio-kicker">{{ $kicker }}</p>@endif
        <div class="bio-call-btns">
            @foreach ($buttons as $btn)
                <a class="bio-call-btn bio-call-btn--{{ $btn['key'] }}" href="{{ $btn['href'] }}" rel="noopener">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="{{ $icons[$btn['key']] }}"/></svg>
                    <span>{{ $btn['label'] }}</span>
                </a>
            @endforeach
        </div>
    </div>
@endif
