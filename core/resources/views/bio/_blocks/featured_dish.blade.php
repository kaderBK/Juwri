{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- featured_dish block: hero dish card. config: {title?, subtitle?, price_label?, schedule_label?, image?, item_id?} --}}
@php
    use Illuminate\Support\Str;

    $b = is_array($block ?? null) ? $block : [];
    $cfg = is_array($b['config'] ?? null) ? $b['config'] : [];
    $storeId = (int) (data_get($store ?? null, 'id') ?? 0) ?: null;
    $title = trim((string) ($cfg['title'] ?? $b['label'] ?? ''));
    $subtitle = trim((string) ($cfg['subtitle'] ?? ''));
    $price = trim((string) ($cfg['price_label'] ?? ''));
    $schedule = trim((string) ($cfg['schedule_label'] ?? ''));
    $url = trim((string) ($b['url'] ?? $cfg['url'] ?? ''));
    $id = $b['id'] ?? '';
    $img = $cfg['image'] ?? null;
    if (is_string($img)) {
        $img = trim($img);
        $img = ($img === '' || Str::startsWith($img, ['http://', 'https://', '/'])) ? $img : media_url('storage/bio/', $img, $storeId);
    } else {
        $img = '';
    }
@endphp
@if ($title !== '')
    <{{ $url !== '' ? 'a' : 'div' }} class="bio-feat" @if ($url !== '') href="{{ $url }}" rel="noopener" @endif @if ($id !== '') data-bio-block="{{ $id }}" @endif>
        <div class="bio-feat__media">
            @if ($img !== '')<img src="{{ $img }}" alt="{{ $title }}" loading="lazy">@else<svg class="hero-ill" viewBox="0 0 120 120" style="width:96px;height:96px"><use href="#ill-platter"/></svg>@endif
            @if ($price !== '')<span class="bio-feat__price">{{ $price }}</span>@endif
        </div>
        <div class="bio-feat__body">
            @if ($schedule !== '')<em>{{ $schedule }}</em>@endif
            <b>{{ $title }}</b>
            @if ($subtitle !== '')<span>{{ $subtitle }}</span>@endif
        </div>
    </{{ $url !== '' ? 'a' : 'div' }}>
@endif
