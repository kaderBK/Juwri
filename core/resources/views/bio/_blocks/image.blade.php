{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- image block: a standalone banner image, optionally linked. config: {image, caption?} + url? --}}
@php
    use Illuminate\Support\Str;

    $b = is_array($block ?? null) ? $block : [];
    $cfg = is_array($b['config'] ?? null) ? $b['config'] : [];
    $storeId = (int) (data_get($store ?? null, 'id') ?? 0) ?: null;
    $caption = trim((string) ($cfg['caption'] ?? $b['label'] ?? ''));
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
@if ($img !== '')
    <{{ $url !== '' ? 'a' : 'figure' }} class="bio-image" @if ($url !== '') href="{{ external_url($url) }}" rel="noopener" @endif @if ($id !== '') data-bio-block="{{ $id }}" @endif>
        <img src="{{ $img }}" alt="{{ $caption }}" loading="lazy">
        @if ($caption !== '')<figcaption class="bio-image__cap">{{ $caption }}</figcaption>@endif
    </{{ $url !== '' ? 'a' : 'figure' }}>
@endif
