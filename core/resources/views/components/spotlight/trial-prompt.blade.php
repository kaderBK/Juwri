{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-spotlight.trial-prompt key="kiosk"> — the last-days nudge, and the state
  after a trial has ended.

  A silent trial expiry is the most expensive kind of churn: nobody decided,
  they just stopped. The chip counts quietly for most of the trial; this speaks
  up only in the last three days, and then once more after it ends.

  On expiry it deliberately does NOT read as a wall. The data is still there and
  the copy says so — the only thing that changed is that the feature is off, and
  continuing puts it back.

  ATTRIBUTES
    key   feature key                                          (required)
--}}
@props(['key'])
@php
    $owner = auth()->user();
    $spot = \App\Services\FeatureSpotlight::for($key, $owner);
    $trial = $spot['trial'] ?? null;
    $left = $trial ? (int) $trial['days_left'] : null;

    // Quiet until the end is in sight; the chip carries the earlier days.
    $ended = $trial && $left < 0;
    $show = $trial
        && ($ended || $left <= 3)
        && \App\Services\FeatureSpotlight::shouldShow($key, $owner, 'teaser');

    $manage = \App\Services\FeatureSpotlight::trialContinueUrl($spot);
@endphp

@if ($show)
    <x-spotlight._beacon />
    {{-- Ended: a neutral inset card (not a wall). Ending soon: the warning tint. --}}
    <div {{ $attributes->merge([
            'class' => 'ui-card spotlight-trial-prompt '.($ended ? 'ui-card--inset' : 'ui-bg-warn'),
            'style' => 'margin-block-end: var(--ui-space-4)'.($ended ? '' : '; border-color: var(--ui-warning-line)'),
        ]) }}
         data-spotlight-key="{{ $key }}" data-spotlight-surface="teaser">
        <div class="ui-cluster">
            <span class="spotlight-teaser__icon {{ $ended ? 'ui-text-2' : 'ui-text-warning' }}" aria-hidden="true">
                <x-ui.icon name="clock" size="20" />
            </span>
            <div class="ui-grow">
                <div class="ui-strong">
                    @if ($ended)
                        {{ ___('Your trial has ended') }}
                    @elseif ($left === 0)
                        {{ ___('Your trial ends today') }}
                    @elseif ($left === 1)
                        {{ ___('Your trial ends tomorrow') }}
                    @else
                        {{ ___('Your trial ends in :days days', ['days' => $left]) }}
                    @endif
                </div>
                <div class="ui-muted">
                    @if ($ended)
                        {{ ___('Your data is still here. Continue to switch it back on.') }}
                    @else
                        {{ ___('Continue to keep it switched on — nothing changes until then.') }}
                    @endif
                    {{-- WI-D1: what happens next, in plain words. --}}
                    @if (\Route::has('spotlight.help'))
                        <a href="{{ route('spotlight.help') }}" style="color: inherit; text-decoration: underline">{{ ___('What happens when it ends?') }}</a>
                    @endif
                </div>
            </div>
            @if ($manage)
                <a href="{{ $manage }}" class="ui-btn ui-btn--sm" data-spotlight-cta>
                    {{ ___('Continue') }}
                </a>
            @endif
            @unless ($ended)
                <button type="button" class="ui-icon-btn ui-icon-btn--ghost" data-spotlight-dismiss
                        aria-label="{{ ___('Dismiss') }}" title="{{ ___('Dismiss') }}"><x-ui.icon name="x" size="18" /></button>
            @endunless
        </div>
    </div>
@endif
