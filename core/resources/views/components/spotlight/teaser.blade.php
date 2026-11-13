{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-spotlight.teaser key="loyalty"> — inline upsell card, shown where the owner
  has just felt the gap (an empty Automations list, a limit they have hit).

  Never a modal and never page-blocking: the research is consistent that an
  in-flow card at the moment of need converts several times better than an
  interruption, and an interruption is what gets a product dismissed for good.

  Renders NOTHING unless FeatureSpotlight says this owner should see it — the
  kill-switch, the white-label rule, the dismissal memory and the one-nudge cap
  all live in the service, so no caller can accidentally nag.

  ATTRIBUTES
    key       feature key                                  (required)
    title     override headline                            (optional)
    body      override body copy                           (optional)
    compact   tighter padding for dense screens            (default: false)
--}}
@props(['key', 'title' => null, 'body' => null, 'compact' => false])
@php
    $owner = auth()->user();
    $show = \App\Services\FeatureSpotlight::shouldShow($key, $owner, 'teaser');
    $spot = $show ? \App\Services\FeatureSpotlight::for($key, $owner) : null;
    $show = $show && $spot && $spot['state'] === 'locked';
    $channel = $show ? \App\Services\FeatureSpotlight::bestChannel($spot['channels']) : null;
@endphp

@if ($show && $channel)
    <x-spotlight._beacon />
    <div {{ $attributes->merge(['class' => 'ui-card spotlight-teaser'.($compact ? ' spotlight-teaser--compact' : '')]) }}
         data-spotlight-key="{{ $key }}" data-spotlight-surface="teaser">
        <span class="spotlight-teaser__icon" aria-hidden="true"><x-ui.icon name="sparkle" size="20" /></span>
        <div class="spotlight-teaser__body">
            {{-- Catalog copy is admin-authored CONTENT: never run through ___(). --}}
            <div class="spotlight-teaser__title">{{ $title ?: $spot['copy']['label'] }}</div>
            <div class="spotlight-teaser__blurb">{{ $body ?: $spot['copy']['blurb'] }}</div>
        </div>
        <div class="spotlight-teaser__actions">
            <a href="{{ $channel['url'] }}" class="ui-btn ui-btn--sm" data-spotlight-cta>
                {{ \App\Services\FeatureSpotlight::cta($channel) }}
            </a>
            <button type="button" class="ui-icon-btn" data-spotlight-dismiss
                    aria-label="{{ ___('Dismiss') }}" title="{{ ___('Dismiss') }}"><x-ui.icon name="x" size="18" /></button>
        </div>
    </div>
@endif
