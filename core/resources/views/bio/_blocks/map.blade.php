{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- map block: location card. config: {address?, lat?, lng?, url?}. Falls back to branch. No external iframe (G6 — CSP has no frame-src). --}}
@php
    $b = is_array($block ?? null) ? $block : [];
    $cfg = is_array($b['config'] ?? null) ? $b['config'] : [];
    $address = trim((string) ($cfg['address'] ?? data_get($branch ?? null, 'address') ?? ''));
    $lat = $cfg['lat'] ?? data_get($branch ?? null, 'latitude');
    $lng = $cfg['lng'] ?? data_get($branch ?? null, 'longitude');
    $url = trim((string) ($b['url'] ?? $cfg['url'] ?? ''));
    if ($url === '' && $lat !== null && $lng !== null && $lat !== '' && $lng !== '') {
        $url = 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($lat.','.$lng);
    } elseif ($url === '' && $address !== '') {
        $url = 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($address);
    }
    $label = trim((string) ($b['label'] ?? '')) ?: ___('Find us');
    $id = $b['id'] ?? '';
@endphp
@if ($address !== '' || $url !== '')
    <a class="bio-card bio-map" @if ($url !== '') href="{{ $url }}" target="_blank" rel="noopener" @endif @if ($id !== '') data-bio-block="{{ $id }}" @endif>
        <div class="bio-map__canvas"><span class="bio-map__pin" aria-hidden="true"><svg class="ig" style="width:24px;height:24px"><use href="#i-pin"/></svg></span></div>
        <p class="bio-kicker">{{ $label }}</p>
        @if ($address !== '')<p class="bio-card__text">{{ $address }}</p>@endif
    </a>
@endif
