{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- text block: a heading and/or paragraph of free text. config: {title?, text?} --}}
@php
    $b = is_array($block ?? null) ? $block : [];
    $cfg = is_array($b['config'] ?? null) ? $b['config'] : [];
    $title = trim((string) ($cfg['title'] ?? $b['label'] ?? ''));
    $body = trim((string) ($cfg['text'] ?? ''));
    $id = $b['id'] ?? '';
@endphp
@if ($title !== '' || $body !== '')
    <div class="bio-text" @if ($id !== '') data-bio-block="{{ $id }}" @endif>
        @if ($title !== '')<p class="bio-text__title">{{ $title }}</p>@endif
        @if ($body !== '')<p class="bio-text__body">{{ $body }}</p>@endif
    </div>
@endif
