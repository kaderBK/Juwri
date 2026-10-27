{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-spotlight.preview key="crm_suite"> — wraps a read-only dashboard an
  unentitled owner is being shown as a glimpse rather than a locked door.

  Showing the real screen with the owner's own numbers behind it beats any
  description of the feature. What is blurred is REAL data, not a mock-up — but
  blur is presentation only, so the controller is what decides what is safe to
  send, and this component only stops the page being used:

    - pointer events off inside the blurred region, so nothing is clickable;
    - inert + aria-hidden, so keyboard and screen readers skip it too, rather
      than tabbing into a control that silently does nothing.

  ATTRIBUTES
    key     feature key                                        (required)
    active  wrap, or pass the page straight through   (default: true)

  `active` exists because Blade pairs a component's opening and closing tags at
  COMPILE time. Putting <x-spotlight.preview> inside @if and its closing tag
  inside a second @if does not conditionally wrap — everything between them
  becomes the slot either way, and the whole page silently renders as nothing
  when the condition is false. So the wrapper is always compiled and the
  decision is made here, at runtime.
--}}
@props(['key', 'active' => true])
@php
    $owner = auth()->user();
    $spot = \App\Services\FeatureSpotlight::for($key, $owner);
    $channel = \App\Services\FeatureSpotlight::bestChannel($spot['channels'] ?? []);
@endphp

@unless ($active)
    {{ $slot }}
@else
<x-spotlight._beacon />
@once
{{-- Preview mode (WI-A3), moved here from the retired old-theme-skin.css so the
     component carries its own presentation. The blur is a tease, never a
     security boundary: what is safe to send is decided server-side. Stage and
     veil share one grid cell, so the wrapper grows to whichever is taller (an
     absolutely positioned veil let the unlock card overflow a short page). --}}
<style nonce="{{ csp_nonce() }}">
    .spotlight-preview { display: grid; }
    .spotlight-preview__stage,
    .spotlight-preview__veil { grid-area: 1 / 1; min-inline-size: 0; }
    .spotlight-preview__stage { filter: blur(6px); -webkit-filter: blur(6px); pointer-events: none; user-select: none; overflow: hidden; }
    .spotlight-preview__veil { display: flex; align-items: center; justify-content: center; padding: var(--ui-space-6) var(--ui-space-4); background: color-mix(in srgb, var(--ui-bg) 55%, transparent); }
    .spotlight-preview__card { inline-size: 100%; max-inline-size: 32rem; margin-inline: auto; text-align: center; box-shadow: var(--ui-shadow-raised); }
    .spotlight-preview__icon { display: inline-flex; align-items: center; justify-content: center; inline-size: 56px; block-size: 56px; margin-block-end: var(--ui-space-3); border-radius: var(--ui-r-tile); background: var(--ui-accent-soft); color: var(--ui-accent-text); }
    .spotlight-preview__card p { margin: 0 0 var(--ui-space-3); }
    @media (prefers-reduced-motion: no-preference) { .spotlight-preview__veil { backdrop-filter: saturate(1.1); } }
</style>
@endonce
<div {{ $attributes->merge(['class' => 'spotlight-preview']) }}
     data-spotlight-key="{{ $key }}" data-spotlight-surface="preview">

    <div class="spotlight-preview__stage" inert aria-hidden="true">
        {{ $slot }}
    </div>

    <div class="spotlight-preview__veil">
        <div class="ui-card ui-card--lg spotlight-preview__card">
            <span class="spotlight-preview__icon" aria-hidden="true">
                <x-ui.icon name="lock" size="24" />
            </span>
            {{-- Catalog copy is admin-authored content — never ___(). --}}
            <h2 class="ui-card__title" style="margin-block-end: var(--ui-space-2)">{{ $spot['copy']['label'] }}</h2>
            <p class="ui-muted">{{ $spot['copy']['blurb'] }}</p>
            <p class="ui-small">{{ ___('This is your own data. Unlock it to read the detail and use the tools.') }}</p>
            @if ($channel)
                <a href="{{ $channel['url'] }}" class="ui-btn" data-spotlight-cta>
                    {{ \App\Services\FeatureSpotlight::cta($channel) }}
                </a>
                @if (\Route::has('feature.locked'))
                    <div style="margin-block-start: var(--ui-space-2)">
                        <a href="{{ route('feature.locked', $key) }}" class="ui-btn ui-btn--link ui-btn--sm ui-text-2">
                            {{ ___('See all the ways to unlock it') }}
                        </a>
                    </div>
                @endif
            @endif
        </div>
    </div>
</div>
@endunless
