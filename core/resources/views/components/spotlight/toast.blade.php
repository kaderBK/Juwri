{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-spotlight.toast key="support_desk"> — "Support Desk is now active. Set up
  your inbox →", once, the first time an owner loads a page after gaining it.

  Entitlement usually arrives while the owner is somewhere else entirely: a plan
  upgrade, an app install, an admin grant. Without this, the only signal that
  anything changed is a nav item quietly appearing.

  The impression is recorded HERE, server-side, rather than by the scroll
  observer every other surface uses — a toast is shown the moment the page
  loads, there is nothing to scroll into view, and recording it is also what
  spends the session's one interruption so nothing else piles on top.

  ATTRIBUTES
    key     feature key                                       (required)
    href    where "set it up" goes                            (optional)
    label   the action link's text                            (optional)
--}}
@props(['key', 'href' => null, 'label' => null])
@php
    $owner = auth()->user();
    $spot = \App\Services\FeatureSpotlight::for($key, $owner);
    $show = $spot['state'] === 'new'
        && \App\Services\FeatureSpotlight::shouldShow($key, $owner, 'toast');

    if ($show) {
        // Rendering IS the impression: it will be on screen a moment from now.
        \App\Services\FeatureSpotlight::record($key, $owner, 'toast', 'impression');
    }
@endphp

@if ($show)
    <x-spotlight._beacon />
    @push('scripts_at_bottom')
    <script nonce="{{ csp_nonce() }}">
        (function () {
            var payload = {
                type: 'success',
                // Catalog copy is admin-authored content — concatenated, never ___().
                message: @js($spot['copy']['label'].' '.___('is now active.')),
                href: @js($href ?: ''),
                actionLabel: @js($label ?: ___('Set it up')),
                spotlightKey: @js($key),
                duration: 9000,
            };
            if (window.dashToast) {
                window.dashToast(payload.type, payload.message, payload);
            } else {
                (window.__dashToastQueue = window.__dashToastQueue || []).push([payload.type, payload.message, payload]);
            }
        })();
    </script>
    @endpush
@endif
