{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- video block (G6): self-hosted <video> only — NO external iframe/provider (keeps the bio CSP tight, no frame-src). config: {media, poster?} --}}
@php
    $b = is_array($block ?? null) ? $block : [];
    $cfg = is_array($b['config'] ?? null) ? $b['config'] : [];
    $storeId = (int) (data_get($store ?? null, 'id') ?? 0) ?: null;

    $src = \Juwri\Core\Support\ViewHelpers::bioMediaUrl($cfg['media'] ?? null, $storeId);
    $poster = \Juwri\Core\Support\ViewHelpers::bioMediaUrl($cfg['poster'] ?? null, $storeId);
    $label = trim((string) ($b['label'] ?? ''));
    $id = $b['id'] ?? '';
@endphp
@if ($src !== '')
    <div class="bio-block--video" @if ($id !== '') data-bio-block="{{ $id }}" @endif>
        @if ($label !== '')<p class="bio-kicker">{{ $label }}</p>@endif
        <div class="bio-video">
            <video controls playsinline preload="metadata" @if ($poster !== '') poster="{{ $poster }}" @endif>
                <source src="{{ $src }}">
            </video>
        </div>
    </div>
@endif
