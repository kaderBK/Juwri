{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- menu_item block: a single featured menu item. config: {item_id, price_label, sub?, image?} --}}
@php
    $b = is_array($block ?? null) ? $block : [];
    $cfg = is_array($b['config'] ?? null) ? $b['config'] : [];
    $label = trim((string) ($b['label'] ?? ''));
    $url = trim((string) ($b['url'] ?? ($cfg['url'] ?? '')));
    $sub = trim((string) ($cfg['sub'] ?? $cfg['subtitle'] ?? ''));
    $price = trim((string) ($cfg['price_label'] ?? ''));
    $id = $b['id'] ?? '';
@endphp
@if ($label !== '')
    <a class="bio-link bio-link--item" href="{{ $url !== '' ? $url : '#' }}" @if ($id !== '') data-bio-block="{{ $id }}" @endif rel="noopener">
        <span class="bio-link__icon" aria-hidden="true"><svg class="ig"><use href="#i-utensils"/></svg></span>
        <span class="bio-link__body">
            <span class="bio-link__label">{{ $label }}</span>
            @if ($sub !== '')<span class="bio-link__sub">{{ $sub }}</span>@endif
        </span>
        @if ($price !== '')<span class="bio-link__price">{{ $price }}</span>@else<span class="bio-link__arrow" aria-hidden="true">→</span>@endif
    </a>
@endif
