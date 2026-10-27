{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- whatsapp block: WhatsApp CTA. config: {phone?, text?}. Falls back to branch.whatsapp / branch.phone. --}}
@php
    $b = is_array($block ?? null) ? $block : [];
    $cfg = is_array($b['config'] ?? null) ? $b['config'] : [];
    $phoneRaw = (string) ($cfg['phone'] ?? data_get($branch ?? null, 'whatsapp') ?? data_get($branch ?? null, 'phone') ?? '');
    $phone = preg_replace('/[^0-9]/', '', $phoneRaw);
    $text = trim((string) ($cfg['text'] ?? ''));
    $label = trim((string) ($b['label'] ?? '')) ?: ___('WhatsApp Us');
    $url = trim((string) ($b['url'] ?? ''));
    if ($url === '' && $phone !== '') {
        $url = 'https://wa.me/'.$phone.($text !== '' ? '?text='.rawurlencode($text) : '');
    }
    $id = $b['id'] ?? '';
@endphp
@if ($url !== '')
    <a class="bio-link bio-link--wa" href="{{ $url }}" target="_blank" rel="noopener" @if ($id !== '') data-bio-block="{{ $id }}" @endif>
        <span class="bio-link__icon" aria-hidden="true"><svg viewBox="0 0 24 24" style="width:20px;height:20px;fill:currentColor;stroke:none"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm5.4 14.1c-.2.7-1.3 1.3-1.8 1.3-.5.1-1 .2-3.3-.7-2.8-1.1-4.6-4-4.7-4.2-.1-.2-1.1-1.5-1.1-2.9s.7-2 1-2.3c.2-.3.5-.3.7-.3h.5c.2 0 .4 0 .6.4l.9 2.1c.1.2.1.4 0 .6l-.4.6-.5.5c-.2.2-.3.4-.1.7.2.3.8 1.4 1.8 2.2 1.2 1.1 2.3 1.4 2.6 1.6.3.1.5.1.7-.1l1-1.2c.2-.3.4-.2.7-.1l2 1c.3.1.5.2.6.4 0 .1 0 .7-.2 1.4z"/></svg></span>
        <span class="bio-link__body"><span class="bio-link__label">{{ $label }}</span>
            @if ($text !== '')<span class="bio-link__sub">{{ $text }}</span>@endif</span>
        <span class="bio-link__arrow" aria-hidden="true">→</span>
    </a>
@endif
