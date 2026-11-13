{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Block loop. Renders $blocks in order, whitelisting the 14 SPEC §3 types. --}}
@php
    $types = ['link','menu','menu_item','hours','map','whatsapp','delivery_apps','reservation','reviews','announcement','socials','capture','video','featured_dish','text','image','divider',
        // bio-t15 Lane A — new types added one at a time as each ships a partial.
        'call', 'delivery', 'giftcard', 'loyalty', 'gallery', 'faq', 'event', 'tipjar'];
    $rows = is_array($blocks ?? null) ? $blocks : [];
@endphp
@if (! empty($rows))
    <div class="bio-blocks">
        @foreach ($rows as $block)
            @php
                $t = is_array($block ?? null) ? (string) ($block['type'] ?? '') : '';
                // bio-t15 A13: pinned/highlight (SPEC §2 A13) are cross-cutting — the
                // wrapper carries the classes so every block type gets them for free.
                $bc = is_array($block['config'] ?? null) ? $block['config'] : [];
                $highlight = in_array($bc['highlight'] ?? '', ['pulse', 'glow'], true) ? $bc['highlight'] : '';
                $pinned = ! empty($bc['pinned'] ?? false);
                $wrapClass = 'bio-block bio-block--'.$t
                    .($pinned ? ' bio-block--pinned' : '')
                    .($highlight !== '' ? ' bio-highlight-'.$highlight : '');
            @endphp
            @if (in_array($t, $types, true))
                <div class="{{ $wrapClass }}">
                    @include('bio._blocks.'.$t, ['block' => $block])
                </div>
            @endif
        @endforeach
    </div>
@endif
