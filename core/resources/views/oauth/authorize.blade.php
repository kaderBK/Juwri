{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<!DOCTYPE html>
{{-- P3-1: branded OAuth consent screen (Passport authorization_code grant).
     The merchant sees exactly which scopes a partner app requests and can
     approve or deny. Contract (hidden fields + routes) matches Passport's
     stock view — only the presentation is ours. --}}
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ brand_title() }} — Authorize {{ $client->name }}</title>
    <style nonce="{{ csp_nonce() }}">
        body { margin:0; min-height:100vh; display:grid; place-items:center; background:#0d1117; color:#e6edf3;
               font:16px/1.6 -apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; }
        .card { background:#161b22; border:1px solid #30363d; border-radius:16px; padding:32px; max-width:440px; width:calc(100% - 40px); }
        h1 { font-size:20px; margin:0 0 6px; }
        p { color:#8b949e; margin:0 0 16px; }
        ul { list-style:none; padding:0; margin:0 0 24px; }
        li { padding:9px 12px; border:1px solid #30363d; border-radius:10px; margin-bottom:8px; font-size:14.5px; }
        li b { color:#3fd463; font-family:ui-monospace,monospace; font-size:13px; display:block; }
        .row { display:flex; gap:10px; }
        button { flex:1; padding:12px; border-radius:999px; border:0; font-weight:700; font-size:15px; cursor:pointer; }
        .approve { background:#3fd463; color:#0e2d18; }
        .deny { background:transparent; color:#e6edf3; border:1px solid #30363d; }
        .meta { margin-top:16px; font-size:12.5px; color:#8b949e; text-align:center; }
    </style>
</head>
<body>
<div class="card">
    <h1>{{ $client->name }} wants access</h1>
    <p>Signed in as <b>{{ $user->email }}</b> on {{ brand_title() }}. This app is requesting:</p>

    <ul>
        @forelse ($scopes as $scope)
            <li><b>{{ $scope->id }}</b>{{ $scope->description }}</li>
        @empty
            <li>Basic access only.</li>
        @endforelse
    </ul>

    <div class="row">
        <form method="post" action="{{ route('passport.authorizations.deny') }}" style="flex:1;display:flex">
            @csrf
            @method('DELETE')
            <input type="hidden" name="state" value="{{ $request->state }}">
            <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit" class="deny">Deny</button>
        </form>
        <form method="post" action="{{ route('passport.authorizations.approve') }}" style="flex:1;display:flex">
            @csrf
            <input type="hidden" name="state" value="{{ $request->state }}">
            <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit" class="approve">Authorize</button>
        </form>
    </div>

    <p class="meta">You'll be redirected to {{ parse_url($client->redirect_uris[0] ?? $client->redirect ?? '', PHP_URL_HOST) ?: 'the app' }}.
       You can revoke access any time from your dashboard.</p>
</div>
</body>
</html>
