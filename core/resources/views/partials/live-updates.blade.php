{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Live updates (Reverb). Prints nothing while broadcasting is off, so the page has no script and no socket.
     Usage: @include('partials.live-updates') once per screen, then JuwriLive.listen(storeId, 'kds', 'kds.changed', fn). --}}
@php($__live = \Juwri\Core\Broadcasting\LiveUpdates::clientConfig())
@if($__live)
<script nonce="{{ csp_nonce() }}">
window.JuwriLiveConfig = @json($__live);
{{-- the client script is deferred: run fn once it is there (the 'load' event comes after every deferred script) --}}
window.JuwriLiveWhenReady = function (fn) { if (window.JuwriLive) { fn(); } else { window.addEventListener('load', function () { if (window.JuwriLive) { fn(); } }); } };
</script>
<script src="{{ asset('assets/js/live-updates.js') }}?v={{ filemtime(public_path('assets/js/live-updates.js')) }}" nonce="{{ csp_nonce() }}" defer></script>
@endif
