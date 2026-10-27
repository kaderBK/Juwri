{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  WI-F4 (upsell-visibility): the entitled owner's side of the spotlight.

  Deliberately placed BELOW the onboarding checklist: a brand-new account is
  being walked through its first week and the spotlight must not compete with
  that (FeatureSpotlight suppresses upsell there outright).

  Both components self-suppress — the service decides, not this file — so the
  loop below is a candidate list, not a decision. At most one ribbon renders per
  render, and a trial chip is furniture rather than a nudge, so a countdown
  never costs the session its one interruption.
--}}
@php
    $__spotOwner = auth()->user();
    $__spotKeys = $__spotOwner ? app(\Juwri\Core\Contracts\Billing\FeatureCommerce::class)->sellableKeys() : [];
@endphp

{{-- WI-A2: a refused create lands back here as often as it lands on the stores
     list, so the upgrade path follows the owner rather than one screen. --}}
@if (session()->has('spotlight_limit'))
    @php $__lim = session('spotlight_limit'); @endphp
    <x-spotlight.limit :type="$__lim['type'] ?? 'restaurant'"
                       :used="$__lim['used'] ?? null"
                       :limit="$__lim['limit'] ?? null" />
@endif

@if ($__spotKeys)
    @php
        // Trials first: a deadline is the only thing here that expires.
        $__spotTrialing = [];
        foreach ($__spotKeys as $__k) {
            if ((\App\Services\FeatureSpotlight::for($__k, $__spotOwner)['state'] ?? null) === 'trialing') {
                $__spotTrialing[] = $__k;
            }
        }
    @endphp

    @if ($__spotTrialing)
        <div class="ui-cluster">
            <span class="ui-muted">{{ ___('On trial') }}</span>
            @foreach ($__spotTrialing as $__k)
                <x-spotlight.trial-chip :key="$__k" />
            @endforeach
        </div>
    @endif

    {{-- WI-B4: the chip counts quietly; this speaks up in the last three days
         and once more after the trial ends. Every trialing key gets its own,
         because two trials ending in the same week are two decisions. --}}
    @foreach ($__spotKeys as $__k)
        <x-spotlight.trial-prompt :key="$__k" />
    @endforeach

    {{-- WI-B3: one row per paid feature that is owned but not yet switched on.
         Deliberately below the trial prompts: a deadline outranks a to-do. --}}
    @php
        $__spotStore = function_exists('dashboard_current_store') ? dashboard_current_store() : null;
        $__spotTodo = app(\Juwri\Core\Contracts\Billing\FeatureCommerce::class)->activationOutstanding($__spotOwner, $__spotStore);
    @endphp
    @foreach (array_keys($__spotTodo) as $__k)
        <x-spotlight.checklist :key="$__k" :store="$__spotStore" compact />
    @endforeach

    {{-- WI-B2: a feature that became active while the owner was elsewhere —
         a plan upgrade, an app install, an admin grant — announces itself once.
         The service caps this at one interruption, so the loop is a candidate
         list rather than a decision. --}}
    @foreach ($__spotKeys as $__k)
        <x-spotlight.toast :key="$__k"
                           :href="\App\Services\FeatureSpotlight::setupUrl($__k)" />
    @endforeach

    @foreach ($__spotKeys as $__k)
        <x-spotlight.ribbon :key="$__k" />
    @endforeach

    {{-- One locked teaser, and one only. A teaser is page furniture rather than
         an interruption, so nothing else caps it — the cap is here on purpose.
         WI-A2 puts teasers where the owner has actually just felt the gap; this
         is the general-purpose one. --}}
    @php
        /* Ask shouldShow(), not just the state: picking by state alone would let
           a DISMISSED teaser occupy the single slot and render nothing, so one
           dismissal would silently retire the surface for good. */
        $__spotLocked = null;
        foreach ($__spotKeys as $__k) {
            if (\App\Services\FeatureSpotlight::shouldShow($__k, $__spotOwner, 'teaser')
                && (\App\Services\FeatureSpotlight::for($__k, $__spotOwner)['state'] ?? null) === 'locked') {
                $__spotLocked = $__k;
                break;
            }
        }
    @endphp
    @if ($__spotLocked)
        <x-spotlight.teaser :key="$__spotLocked" compact />
    @endif
@endif
