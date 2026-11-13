{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-spotlight.ribbon key="loyalty"> — "Loyalty is included in your Premium plan."

  The half that usually gets skipped. Someone who does not know a feature is
  included cannot value it at renewal, so a paid feature nobody switches on is a
  cancellation with a delay on it. Shown once, then never again: seeing it IS
  the record (an impression row), so a reload does not repeat it.

  ATTRIBUTES
    key      feature key                                     (required)
    action   ['url' => …, 'label' => …] setup step to offer   (optional)
--}}
@props(['key', 'action' => null])
@php
    $owner = auth()->user();
    $show = \App\Services\FeatureSpotlight::shouldShow($key, $owner, 'ribbon');
    $spot = $show ? \App\Services\FeatureSpotlight::for($key, $owner) : null;
    $show = $show && $spot && in_array($spot['state'], ['included', 'new'], true);
    $planName = $show ? \App\Services\FeatureSpotlight::planName($owner) : null;
@endphp

@if ($show)
    <x-spotlight._beacon />
    {{-- Kit success banner (role="status"); CTA and dismiss sit in its body. --}}
    <x-ui.banner tone="success" :attributes="$attributes->merge([
            'class' => 'spotlight-ribbon',
            'style' => 'margin-block-end: var(--ui-space-4)',
            'data-spotlight-key' => $key,
            'data-spotlight-surface' => 'ribbon',
        ])">
        <div class="ui-cluster">
            <div class="ui-grow">
                <div class="ui-strong">
                    @if ($planName)
                        {{ ___('This is included in your plan') }} — {{ $planName }}
                    @else
                        {{ ___('This is included in your plan') }}
                    @endif
                </div>
                <div style="font-weight: 400">{{ ___('You already have it. Here is how to switch it on.') }}</div>
            </div>
            @if ($action)
                <a href="{{ $action['url'] }}" class="ui-btn ui-btn--success ui-btn--sm" data-spotlight-cta>
                    {{ $action['label'] ?? ___('Set it up') }}
                </a>
            @endif
            <button type="button" class="ui-icon-btn ui-icon-btn--ghost" data-spotlight-dismiss
                    aria-label="{{ ___('Dismiss') }}" title="{{ ___('Dismiss') }}"><x-ui.icon name="x" size="18" /></button>
        </div>
    </x-ui.banner>
@endif
