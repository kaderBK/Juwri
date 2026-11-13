{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- NexLink toast container fed by the Alpine $store.toast (owner-ui/services.js). --}}
<div class="dash-toast-wrap" x-data role="status" aria-live="polite" aria-atomic="true" x-cloak>
    <template x-for="t in $store.toast.items" :key="t.id">
        {{-- N2: slide/fade choreography via CSS keyframes (.dash-toast /
             .is-leaving), hover suspends the auto-dismiss timer (pause/resume). --}}
        <div class="dash-toast" :class="['type-' + t.type, { 'is-leaving': t.leaving }]"
             @mouseenter="$store.toast.pause(t.id)" @mouseleave="$store.toast.resume(t.id)">
            <span x-text="t.message"></span>
            {{-- WI-B2: optional action link. A toast that says a feature is now
                 active is only useful if it can also take you to it. --}}
            <template x-if="t.href">
                <a class="dash-toast__action"
                   :href="t.href"
                   :data-spotlight-key="t.spotlightKey || null"
                   :data-spotlight-surface="t.spotlightKey ? 'toast' : null"
                   :data-spotlight-cta="t.spotlightKey ? '' : null"
                   x-text="t.actionLabel"></a>
            </template>
        </div>
    </template>
</div>

@php
    $flashes = [];
    foreach (['success', 'info', 'warning', 'danger', 'error'] as $key) {
        if (session()->has($key)) {
            $type = $key === 'error' ? 'danger' : $key;
            $flashes[] = ['type' => $type, 'message' => session($key)];
        }
    }
    // App-wide app_alert() channel (app_alert_message/_type) — used by 85+ controllers.
    if (session()->has('app_alert_message')) {
        $qt = session('app_alert_type', 'success');
        $qt = $qt === 'error' ? 'danger' : $qt;
        $flashes[] = ['type' => $qt, 'message' => session('app_alert_message')];
    }
@endphp

@if(count($flashes))
{{-- Use window.dashToast (owner-ui/services.js): queues if Alpine's toast store isn't ready
     yet (first load) and flushes on alpine:init; adds immediately on Turbo visits
     where the store already exists. This script re-runs on each Turbo navigation. --}}
<script nonce="{{ csp_nonce() }}">
    (function () {
        var flashes = @json(\Juwri\Core\Support\ViewHelpers::flashPayload($flashes));
        flashes.forEach(function (f) {
            // Turbo visit: dashToast exists → show now. First load: services.js is
            // deferred and hasn't defined it yet → queue; services.js flushes
            // window.__dashToastQueue on alpine:init.
            if (window.dashToast) window.dashToast(f.type, f.message);
            else (window.__dashToastQueue = window.__dashToastQueue || []).push([f.type, f.message, {}]);
        });
    })();
</script>
@endif
