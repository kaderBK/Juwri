{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- announcement block: short notice. config: {text} --}}
@php
    $b = is_array($block ?? null) ? $block : [];
    $cfg = is_array($b['config'] ?? null) ? $b['config'] : [];
    $text = trim((string) ($cfg['text'] ?? $b['label'] ?? ''));
    $id = $b['id'] ?? '';
@endphp
@if ($text !== '')
    <div class="bio-ann" @if ($id !== '') data-bio-block="{{ $id }}" @endif>
        <span class="bio-ann__icon" aria-hidden="true"><svg class="ib"><use href="#i-megaphone"/></svg></span>{{ $text }}
    </div>
@endif
