{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-spotlight.checklist key="loyalty"> — the two or three steps between owning
  a feature and actually using it.

  Owning is not using: a paid feature nobody switched on is a cancellation with
  a delay on it. Every step is a question asked of real data (is there a station,
  is there a loyalty program, has a till been opened), never a stored "done"
  flag — a flag survives the thing it was set for and then reports an activation
  that no longer exists.

  Renders nothing once every step is done. A checklist that lingers after
  completion is clutter, and clutter is what gets the next one ignored.

  ATTRIBUTES
    key      feature key                                       (required)
    store    the store to check against  (default: dashboard context store)
    compact  hide the per-step description                     (default: false)
--}}
@props(['key', 'store' => null, 'compact' => false])
@php
    $owner = auth()->user();
    $target = $store ?: (function_exists('dashboard_current_store') ? dashboard_current_store() : null);

    $show = $owner
        && $target
        && \App\Services\FeatureSpotlight::gateState($key, $owner) === 'ok';

    $progress = $show ? app(\Juwri\Core\Contracts\Billing\FeatureCommerce::class)->activationProgress($key, $target, $owner) : null;
    // Community (no Billing) reports no activation progress at all: nothing to show.
    $show = $show && ($progress['total'] ?? 0) > 0 && ! ($progress['complete'] ?? false);

    // Per-feature heading — before this, every checklist card (inbox, stations,
    // loyalty, till, campaigns) rendered the same "Finish setting this up".
    // Localize the registry label only when it is already a lang key; never let
    // ___() auto-register a feature label as a side effect of rendering.
    $featureLabel = \App\Services\FeatureRegistry::keyed()[$key]['label'] ?? null;
    $checklistTitle = $featureLabel
        ? (\Illuminate\Support\Facades\Lang::has('lang.'.\Illuminate\Support\Str::slug($featureLabel, '_'))
            ? ___($featureLabel)
            : $featureLabel)
        // pos_enabled is a FeatureActivation key with no FeatureRegistry entry —
        // title it from the existing localized 'pos' lang key.
        : ($key === 'pos_enabled' ? ___('pos') : ___('Finish setting this up'));
@endphp

@if ($show)
    <section {{ $attributes->merge(['class' => 'ui-card spotlight-checklist']) }}>
        <header class="spotlight-checklist__head">
            <h2 class="spotlight-checklist__title">{{ $checklistTitle }}</h2>
            <p class="spotlight-checklist__count">
                <strong class="ui-ltr">{{ $progress['done'] }}/{{ $progress['total'] }}</strong> {{ ___('steps completed') }}
            </p>
        </header>

        <span class="ui-ov-meter spotlight-checklist__meter" role="progressbar" aria-label="{{ $checklistTitle }}"
              aria-valuenow="{{ $progress['pct'] }}" aria-valuemin="0" aria-valuemax="100"><span style="inline-size: {{ $progress['pct'] }}%"></span></span>

        <ul class="spotlight-checklist__steps">
            @foreach ($progress['steps'] as $step)
                <li class="spotlight-checklist__step" @if ($step['done']) data-done @endif>
                    <span class="spotlight-checklist__dot" aria-hidden="true">
                        @if ($step['done'])<x-ui.icon name="check" size="16" />@endif
                    </span>
                    <div class="spotlight-checklist__body">
                        <div class="spotlight-checklist__step-title">{{ $step['title'] }}</div>
                        @unless ($compact || $step['done'])
                            <div class="spotlight-checklist__desc">{{ $step['desc'] }}</div>
                        @endunless
                    </div>
                    @if (! $step['done'] && $step['url'])
                        <a href="{{ $step['url'] }}" class="ui-btn ui-btn--secondary ui-btn--sm spotlight-checklist__cta">
                            {{ $step['btn'] ?: ___('Set it up') }}
                        </a>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>
@endif
