{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', $app->name)
@section('page_title', $app->name)
@section('page_sub', ___('Embedded app — running on the partner\'s servers, authenticated with short-lived session tokens.'))

@section('content')
<div>
    {{-- P3-3: the app itself. Session tokens are pushed to it via postMessage
         and refreshed before expiry — no long-lived secret in the browser. --}}
    <iframe id="appFrame"
            src="{{ $app->embed_url }}"
            title="{{ $app->name }}"
            class="ui-w-full ui-bordered"
            style="block-size:calc(100vh - 220px);"
            sandbox="allow-scripts allow-same-origin allow-forms"
            referrerpolicy="origin"></iframe>
</div>

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
(function () {
    const frame = document.getElementById('appFrame');
    const appOrigin = new URL(@json($app->embed_url)).origin;

    async function pushToken() {
        try {
            const r = await fetch('{{ route('apps.sessionToken', $app->id) }}', { headers: { Accept: 'application/json' } });
            if (!r.ok) return;
            const d = await r.json();
            // Only the app's own origin may receive the token.
            frame.contentWindow.postMessage({ type: 'mb:session-token', token: d.token }, appOrigin);
            // Refresh well before the 60s expiry.
            setTimeout(pushToken, Math.max((d.expires_in - 10) * 1000, 15000));
        } catch (e) { /* retry on next load */ }
    }

    frame.addEventListener('load', pushToken);
    // The app may also request a fresh token explicitly.
    window.addEventListener('message', function (ev) {
        if (ev.origin === appOrigin && ev.data && ev.data.type === 'mb:request-token') pushToken();
    });
})();
</script>
@endpush
@endsection
