{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- reservation block: booking CTA (bio-t15 A3, SPEC §2 — link/WhatsApp based, no
     booking-module dependency). config: {mode:'url'|'whatsapp', url?, whatsapp?,
     party_note?, label?}. Falls back to the legacy {note?, url?} shape (still read
     via `$b['url']`/`cfg['note']`) for blocks saved before this run. Degrades to
     nothing when neither destination resolves. --}}
@php
    $b = is_array($block ?? null) ? $block : [];
    $cfg = is_array($b['config'] ?? null) ? $b['config'] : [];
    $label = trim((string) ($b['label'] ?? $cfg['label'] ?? '')) ?: ___('Reserve a Table');
    $note = trim((string) ($cfg['party_note'] ?? $cfg['note'] ?? $cfg['sub'] ?? ''));
    $mode = ($cfg['mode'] ?? '') === 'whatsapp' ? 'whatsapp' : 'url';
    $id = $b['id'] ?? '';

    $url = '';
    if ($mode === 'whatsapp') {
        $wa = trim((string) ($cfg['whatsapp'] ?? ''));
        if ($wa !== '') {
            $text = ___('Hi! I\'d like to reserve a table').($note !== '' ? ' — '.$note : '');
            $url = whatsapp_link(whatsapp_normalize_number($wa), $text);
        }
    } else {
        $url = trim((string) ($cfg['url'] ?? $b['url'] ?? ''));
    }
@endphp
@if ($url !== '')
    <a class="bio-cta bio-cta--reserve" href="{{ $url }}" @if ($mode === 'whatsapp') target="_blank" @endif rel="noopener" @if ($id !== '') data-bio-block="{{ $id }}" @endif>
        <span class="bio-cta__body"><span class="bio-cta__label">{{ $label }}</span>
            @if ($note !== '')<small>{{ $note }}</small>@endif</span>
    </a>
@endif
