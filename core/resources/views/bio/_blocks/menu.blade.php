{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- menu block: link to the store menu. config: {sub?, url?} --}}
@php
    $b = is_array($block ?? null) ? $block : [];
    $cfg = is_array($b['config'] ?? null) ? $b['config'] : [];
    $label = trim((string) ($b['label'] ?? '')) ?: ___('Menu');
    $url = trim((string) ($b['url'] ?? ($cfg['url'] ?? '')));
    $sub = trim((string) ($cfg['sub'] ?? $cfg['subtitle'] ?? ''));
    $id = $b['id'] ?? '';
@endphp
<a class="bio-link bio-link--menu" href="{{ $url !== '' ? $url : '#' }}" @if ($id !== '') data-bio-block="{{ $id }}" @endif rel="noopener">
    <span class="bio-link__icon" aria-hidden="true"><svg class="ig"><use href="#i-menu"/></svg></span>
    <span class="bio-link__body">
        <span class="bio-link__label">{{ $label }}</span>
        @if ($sub !== '')<span class="bio-link__sub">{{ $sub }}</span>@endif
    </span>
    <span class="bio-link__arrow" aria-hidden="true">→</span>
</a>
