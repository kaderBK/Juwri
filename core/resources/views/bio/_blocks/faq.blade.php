{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- faq block: accordion (bio-t15 A9, SPEC §2). config: {items:[{q,a}]} — edited
     via the same "left | right" config_pairs textarea idiom, remapped q|a. Native
     <details><summary> (no JS, keyboard-accessible). --}}
@php
    $b = is_array($block ?? null) ? $block : [];
    $cfg = is_array($b['config'] ?? null) ? $b['config'] : [];
    $label = trim((string) ($b['label'] ?? $cfg['label'] ?? '')) ?: ___('FAQ');
    $items = is_array($cfg['items'] ?? null) ? $cfg['items'] : [];
    $items = \Juwri\Core\Support\ViewHelpers::faqWithBoth($items);
    $id = $b['id'] ?? '';
@endphp
@if (! empty($items))
    <div class="bio-faq" @if ($id !== '') data-bio-block="{{ $id }}" @endif>
        @if ($label !== '')<p class="bio-kicker">{{ $label }}</p>@endif
        @foreach ($items as $item)
            <details class="bio-faq__item">
                <summary>{{ trim((string) $item['q']) }}</summary>
                <div class="bio-faq__a">{{ trim((string) $item['a']) }}</div>
            </details>
        @endforeach
    </div>
@endif
