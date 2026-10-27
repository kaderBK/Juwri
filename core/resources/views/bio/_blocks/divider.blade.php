{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- divider block: a visual separator, with an optional centered label. --}}
@php
    $b = is_array($block ?? null) ? $block : [];
    $label = trim((string) ($b['label'] ?? ''));
    $id = $b['id'] ?? '';
@endphp
<div class="bio-divider @if ($label !== '') bio-divider--labelled @endif" role="separator" @if ($id !== '') data-bio-block="{{ $id }}" @endif>
    @if ($label !== '')<span>{{ $label }}</span>@endif
</div>
