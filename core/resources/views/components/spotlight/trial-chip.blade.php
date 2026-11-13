{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-spotlight.trial-chip key="support_desk"> — "Trial · 6 days left".

  AppInstallation.trial_ends_at has been populated and shown nowhere. A silent
  trial expiry is the most expensive kind of churn: nobody decided, they just
  stopped. This is page furniture, not a nudge — it does not spend the session's
  one interruption — and it turns urgent in the last three days.

  ATTRIBUTES
    key    feature key                                       (required)
--}}
@props(['key'])
@php
    $owner = auth()->user();
    $spot = \App\Services\FeatureSpotlight::for($key, $owner);
    $trial = $spot['trial'] ?? null;
    $show = $trial && \App\Services\FeatureSpotlight::shouldShow($key, $owner, 'trial_chip');
    $left = (int) ($trial['days_left'] ?? 0);
    $urgent = $left <= 3;
@endphp

@if ($show)
    <x-spotlight._beacon />
    {{-- Kit tag: neutral while the trial runs, warning in the last three days. --}}
    <span {{ $attributes->merge(['class' => 'ui-tag spotlight-trial-chip']) }}
          data-tone="{{ $urgent ? 'warning' : 'neutral' }}"
          data-spotlight-key="{{ $key }}" data-spotlight-surface="trial_chip"
          title="{{ ___('Trial ends').' '.$trial['ends_at']->isoFormat('LL') }}">
        <x-ui.icon name="clock" size="14" />
        @if ($left > 1)
            {{ ___('Trial') }} · {{ ___(':days days left', ['days' => $left]) }}
        @elseif ($left === 1)
            {{ ___('Trial') }} · {{ ___('1 day left') }}
        @elseif ($left === 0)
            {{ ___('Trial ends today') }}
        @else
            {{ ___('Trial ended') }}
        @endif
    </span>
@endif
