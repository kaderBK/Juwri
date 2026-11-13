{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- link block: generic tappable link card. config: {sub?, icon?} --}}
@php
    $b = is_array($block ?? null) ? $block : [];
    $cfg = is_array($b['config'] ?? null) ? $b['config'] : [];
    $label = trim((string) ($b['label'] ?? ''));
    $url = trim((string) ($b['url'] ?? ($cfg['url'] ?? '')));
    $sub = trim((string) ($cfg['sub'] ?? $cfg['subtitle'] ?? ''));
    $icon = preg_replace('/[^a-z0-9-]/', '', (string) ($cfg['icon'] ?? 'i-tag')) ?: 'i-tag';
    $id = $b['id'] ?? '';
@endphp
@if ($label !== '' || $url !== '')
    <a class="bio-link" href="{{ external_url($url) }}" @if ($id !== '') data-bio-block="{{ $id }}" @endif rel="noopener">
        <span class="bio-link__icon" aria-hidden="true"><svg class="ig"><use href="#{{ $icon }}"/></svg></span>
        <span class="bio-link__body">
            <span class="bio-link__label">{{ $label !== '' ? $label : $url }}</span>
            @if ($sub !== '')<span class="bio-link__sub">{{ $sub }}</span>@endif
        </span>
        <span class="bio-link__arrow" aria-hidden="true">→</span>
    </a>
@endif
