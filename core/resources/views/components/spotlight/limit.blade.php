{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-spotlight.limit type="restaurant"> — the upgrade path at the moment the
  owner is told they cannot add another one.

  This is the "expansion nudge": the highest-intent moment in the product, and
  until now it ended in a bare red toast that named a limit and offered no way
  past it. Renders only when something actually raises that ceiling.

  A limit key is not plan-gated, so the tri-state gate says 'ok' about it and
  the ordinary state machine has nothing to say — hence its own resolver.

  ATTRIBUTES
    type    bare limit name: restaurant | menu | scan | category   (required)
    used    current count, for an honest "N of N used" line         (optional)
    limit   the ceiling                                             (optional)
--}}
@props(['type', 'used' => null, 'limit' => null])
@php
    $offer = \App\Services\FeatureSpotlight::limitOffer($type, auth()->user());
@endphp

@if ($offer && $offer['channel'])
    <x-spotlight._beacon />
    {{-- Kit card on the warning tint; the icon tile reuses the teaser's. --}}
    <div {{ $attributes->merge([
            'class' => 'ui-card ui-bg-warn spotlight-limit',
            'style' => 'border-color: var(--ui-warning-line); margin-block-end: var(--ui-space-4)',
        ]) }}
         data-spotlight-key="{{ $offer['key'] }}" data-spotlight-surface="teaser">
        <div class="ui-cluster">
            <span class="spotlight-teaser__icon ui-text-warning" aria-hidden="true"><x-ui.icon name="trend-up" size="20" /></span>
            <div class="ui-grow">
                <div class="ui-strong">{{ ___('You have reached your limit') }}</div>
                <div class="ui-muted">
                    @if ($used !== null && $limit !== null)
                        {{ ___(':used of :limit used', ['used' => $used, 'limit' => $limit]) }} ·
                    @endif
                    {{ $offer['channel']['detail'] ?? $offer['blurb'] }}
                </div>
            </div>
            <a href="{{ $offer['channel']['url'] }}" class="ui-btn ui-btn--sm" data-spotlight-cta>
                {{ $offer['cta'] }}
            </a>
        </div>
    </div>
@endif
