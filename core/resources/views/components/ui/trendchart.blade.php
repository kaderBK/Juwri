{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-ui.trendchart :series="[['name' => 'Sales', 'data' => [..], 'fill' => true], ['name' => 'Orders', 'data' => [..]]]" />

  A light inline SVG line chart for a short daily series (no chart library).
  The first series is the accent line (optionally with a soft area under it);
  the others are dashed secondary lines. Each series is scaled to its own
  min–max, so the chart shows shape, not comparable magnitudes — pair it with a
  legend that states the totals. A series with fewer than two points is skipped.
    series  list of ['name' => string, 'data' => number[], 'fill' => bool]
    height  viewBox height (the element stretches to its box; default 44)
--}}
@props(['series' => [], 'height' => 44, 'labels' => []])
@php
    $__w = 100;
    $__h = max(10, (int) $height);
    $__inset = 4;
    $__lines = [];
    foreach ($series as $__s) {
        $__values = array_map('floatval', array_values($__s['data'] ?? []));
        $__count = count($__values);
        if ($__count < 2) {
            continue;
        }
        $__lo = min($__values);
        $__span = (max($__values) - $__lo) ?: 1;
        $__points = [];
        foreach ($__values as $__i => $__v) {
            $__points[] = [
                round($__i * $__w / ($__count - 1), 2),
                round($__h - $__inset - ($__v - $__lo) / $__span * ($__h - 2 * $__inset), 2),
            ];
        }
        $__path = \Juwri\Core\Support\ViewHelpers::polyline($__points);
        $__lines[] = [
            'path' => $__path,
            'area' => '0,'.$__h.' '.$__path.' '.$__w.','.$__h,
            'end' => end($__points),
            'fill' => (bool) ($__s['fill'] ?? false),
        ];
    }
    $__label = \Juwri\Core\Support\ViewHelpers::seriesLabel($series);
@endphp
@if (count($__lines))
<svg {{ $attributes->class('ui-trend trendchart') }} viewBox="0 0 {{ $__w }} {{ $__h }}" preserveAspectRatio="none" role="img" aria-label="{{ $__label }} {{ ___('trend') }}">
@foreach ([0.25, 0.5, 0.75] as $__g)
<line class="ui-trend__grid" x1="0" y1="{{ round($__h * $__g, 2) }}" x2="{{ $__w }}" y2="{{ round($__h * $__g, 2) }}" vector-effect="non-scaling-stroke" />
@endforeach
@foreach ($__lines as $__n => $__line)
@if ($__line['fill'])
<polygon class="ui-trend__area" points="{{ $__line['area'] }}" />
@endif
<polyline @class(['ui-trend__line', 'ui-trend__line--alt' => $__n > 0]) points="{{ $__line['path'] }}" vector-effect="non-scaling-stroke" />
<circle @class(['ui-trend__dot', 'ui-trend__dot--alt' => $__n > 0]) cx="{{ $__line['end'][0] }}" cy="{{ $__line['end'][1] }}" r="1.6" vector-effect="non-scaling-stroke" />
@endforeach
</svg>
@endif
