{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-ui.pill tone="success">{{ ___('Open') }}</x-ui.pill> — status with a dot.
    tone  success|warning|danger|neutral|accent (default neutral)
    live  true → the dot pulses (only for live connections)
  Status colour is always paired with the word.
--}}
@props(['tone' => 'neutral', 'live' => false])
<span {{ $attributes->class(['ui-pill', 'ui-pill--live' => $live]) }} data-tone="{{ $tone }}">{{ $slot }}</span>
