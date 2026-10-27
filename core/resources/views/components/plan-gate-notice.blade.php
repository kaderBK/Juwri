{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  Pricing follow-ups (PRICING.md §3): upsell notice for a plan-gated feature, shown only when the owner's plan
  withholds it (a plan without the key keeps the feature). The server-side refusal lives in
  Juwri\Core\Support\PlanGate; this is only the explanation next to the switch.
  <x-plan-gate-notice feature="delivery" />
--}}
@props(['feature', 'owner' => null])
@php
    $__owner = $owner ?? (function_exists('dashboard_owner') ? dashboard_owner() : auth()->user());
    $__url = \Juwri\Core\Support\PlanGate::upgradeUrl();
@endphp
@unless (\Juwri\Core\Support\PlanGate::allows($__owner, $feature))
    <x-ui.banner tone="warning" data-plan-gate="{{ $feature }}" {{ $attributes }}>
        {{ \Juwri\Core\Support\PlanGate::upsell($feature) }}
        @if ($__url)
            <a href="{{ $__url }}">{{ ___('See plans') }}</a>
        @endif
    </x-ui.banner>
@endunless
