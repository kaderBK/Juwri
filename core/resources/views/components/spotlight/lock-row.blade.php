{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-spotlight.lock-row key="hotel" :label="…" :icon="…" :url="…"> — a nav row for
  a feature the owner does not have.

  Deliberately NOT a generic padlock replacing the icon: the feature's own icon
  dimmed, plus a small lock chip, so the row still reads as the thing it is. The
  tooltip carries the shortest true sell — name, one line, cheapest price, trial
  if any — because a lock with no price gives nobody a reason to click.

  Never hidden to "tidy up" the nav: hidden is not discoverable, and being seen
  is the entire point.

  ATTRIBUTES
    key     feature key                               (required)
    label   nav label                                 (required)
    icon    the feature's real icon (legacy icon-font key or a
            Tabler/kit name, drawn as SVG by x-ui.shell.nav-icon)   (required)
    url     where the row goes (the upsell page)      (required)
    active  render as the current page                (default: false)
    surface which surface this row belongs to         (default: nav)

  `surface` matters: the same row is used in the sidebar and as a cross-sell
  list on the locked page, and reporting both as "nav" would quietly corrupt the
  nav numbers in the conversion report.
--}}
@props(['key', 'label', 'icon', 'url', 'active' => false, 'surface' => 'nav'])
@php
    $owner = auth()->user();
    $spot = \App\Services\FeatureSpotlight::for($key, $owner);
    $channel = \App\Services\FeatureSpotlight::bestChannel($spot['channels'] ?? []);
    $tell = \App\Services\FeatureSpotlight::shouldShow($key, $owner, $surface);

    /* Sell line and tooltip come from the service, which the SIDEBAR also uses
       (WI-A1) — the two can never quote different numbers for one feature. */
    $sell = \App\Services\FeatureSpotlight::chipLine($key, $owner);
    $tip = \App\Services\FeatureSpotlight::tooltip($key, $owner);
@endphp

{{-- Dimmed kit row: the feature's own icon, its label, and a lock tag carrying
     the cheapest true sell. --}}
<a href="{{ $url }}"
   {{ $attributes->merge([
       'class' => 'ui-cluster spotlight-lock-row'.($active ? ' active' : ''),
       'style' => 'gap: var(--ui-space-2); flex-wrap: nowrap; opacity: .75; color: inherit; text-decoration: none',
   ]) }}
   @if ($active) aria-current="page" @endif
   title="{{ trim($tip) }}"
   @if ($tell) data-spotlight-key="{{ $key }}" data-spotlight-surface="{{ $surface }}" data-spotlight-cta @endif>
    <x-ui.shell.nav-icon :glyph="$icon" size="18" />
    <span class="ui-grow ui-truncate">{{ $label }}</span>
    <span class="ui-tag" data-tone="neutral" aria-label="{{ ___('Not in your plan') }}">
        <x-ui.icon name="lock" size="14" />
        @if ($channel && ! empty($channel['trial_days']))
            <span>{{ ___('Free trial') }}</span>
        @elseif ($channel && ! empty($channel['price']))
            <span>{{ $channel['price'] }}</span>
        @elseif ($sell)
            <span>{{ $sell }}</span>
        @endif
    </span>
</a>
@if ($tell)
    <x-spotlight._beacon />
@endif
