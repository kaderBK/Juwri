{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- gallery block: photo carousel / grid (bio-t15 A8, SPEC §2). config:
     {images:[media|{image,caption?}], layout:'carousel'|'grid', ratio:'1:1'|'4:3'|'16:9'}.
     `carousel` = CSS scroll-snap (no JS lib, CSP-safe); `grid` = 2-col. Images via
     media_url() under the same storage/bio/ convention the `image` block uses.
     Lazy-loaded; capped at 12. --}}
@php
    $b = is_array($block ?? null) ? $block : [];
    $cfg = is_array($b['config'] ?? null) ? $b['config'] : [];
    $storeId = (int) (data_get($store ?? null, 'id') ?? 0) ?: null;
    $layout = ($cfg['layout'] ?? '') === 'grid' ? 'grid' : 'carousel';
    $ratio = in_array($cfg['ratio'] ?? '', ['1:1', '4:3', '16:9'], true) ? $cfg['ratio'] : '1:1';
    $id = $b['id'] ?? '';

    $raw = is_array($cfg['images'] ?? null) ? $cfg['images'] : [];
    $images = \Juwri\Core\Support\Views\PublicSiteViewHelpers::galleryImageEntries($raw, $storeId);
@endphp
@if (! empty($images))
    <div class="bio-gallery bio-gallery--{{ $layout }} bio-gallery--r{{ str_replace(':', '-', $ratio) }}" @if ($id !== '') data-bio-block="{{ $id }}" @endif>
        @foreach ($images as $img)
            <figure class="bio-gallery__item">
                <img src="{{ $img['url'] }}" alt="{{ $img['caption'] }}" loading="lazy">
                @if ($img['caption'] !== '')<figcaption>{{ $img['caption'] }}</figcaption>@endif
            </figure>
        @endforeach
    </div>
@endif
