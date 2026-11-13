{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-skeleton> — loading placeholder drawn with the owner UI kit's shimmer
  (ui-skeleton__block / ui-skeleton__line, components.css; static under reduced
  motion). Use while content 1–3s+ loads.

  ATTRIBUTES
    rows     number of shimmer text lines           (default: 3)
    avatar   render a leading circle avatar block    (default: false)
    height   line height in px                       (default: 12)
    lastNarrow  make the final line ~60% width       (default: true)
    class    merged onto the wrapper.

  EXAMPLE
    <div x-show="loading"><x-skeleton :rows="4" avatar /></div>
    <template x-if="!loading"> … real content … </template>
--}}
@props([
    'rows' => 3,
    'avatar' => false,
    'height' => 12,
    'lastNarrow' => true,
])
@php
    $rows = max(1, (int) $rows);
    $h = (int) $height;
@endphp
<div {{ $attributes->merge(['class' => 'ui-skeleton']) }} aria-hidden="true">
    <div class="ui-skeleton__row" style="align-items: flex-start; padding-block: 0">
        @if($avatar)
            <span class="ui-skeleton__block" style="border-radius: 50%;"></span>
        @endif
        <div class="ui-skeleton__lines">
            @for($i = 0; $i < $rows; $i++)
                @php
                    $isLast = $i === $rows - 1;
                    $w = ($lastNarrow && $isLast && $rows > 1) ? 60 : (95 - ($i % 3) * 8);
                @endphp
                <span class="ui-skeleton__line" style="display: block; inline-size: {{ $w }}%; block-size: {{ $h }}px;"></span>
            @endfor
        </div>
    </div>
</div>
