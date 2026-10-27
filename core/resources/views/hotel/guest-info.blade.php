{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ in_array(app()->getLocale(), ['ar','fa','he','ur']) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- C5 — the page carries Wi-Fi credentials and house rules: keep it out of search. --}}
    <meta name="robots" content="noindex, nofollow">
    <title>{{ ___('Guest information') }} · {{ $store->title }}</title>
    <style nonce="{{ csp_nonce() }}">
        :root { --bg:#f6f7f9; --card:#fff; --ink:#111; --muted:#667; --line:#e6e8ec; --brand:#0d6efd; }
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; margin:0; background:var(--bg); color:var(--ink); }
        .wrap { max-width: 560px; margin: 0 auto; padding: 20px 16px 48px; }
        header h1 { font-size: 22px; margin: 4px 0; }
        header .room { color: var(--muted); font-size: 14px; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:14px; padding:16px; margin-top:14px; }
        .card h2 { font-size:15px; margin:0 0 8px; text-transform:uppercase; letter-spacing:.04em; color:var(--muted); }
        .kv { display:flex; justify-content:space-between; gap:12px; padding:4px 0; }
        .kv b { font-weight:600; }
        .rules { white-space:pre-line; line-height:1.5; }
        .btn { display:inline-block; padding:10px 16px; border-radius:10px; background:var(--brand); color:#fff; text-decoration:none; font-weight:600; margin:4px 6px 0 0; }
        .btn.alt { background:#eef; color:var(--brand); }
        form.req { margin-top:8px; }
        form.req select, form.req textarea { width:100%; padding:10px; border:1px solid var(--line); border-radius:10px; margin-bottom:8px; font:inherit; }
        .ok { background:#e7f7ec; color:#137a3a; padding:10px 14px; border-radius:10px; margin-top:12px; }
    </style>
</head>
<body>
<div class="wrap">
    <header>
        <div class="room">
            @if($room){{ ___('Room') }} #{{ $room->table_no }}@if($room->label) · {{ $room->label }}@endif @endif
        </div>
        <h1>{{ ___('Welcome to') }} {{ $store->title }}</h1>
    </header>

    @if(session('success'))<div class="ok">{{ session('success') }}</div>@endif

    {{-- C5 — reciprocal link back to the room's menu when a room QR resolved this page. --}}
    @if($room && $room->key)
        <a class="btn" href="{{ route('publicView', $store->slug) }}?table={{ $room->key }}">{{ ___('View menu / order') }}</a>
    @endif

    @php
        $wifi = $hotelSettings['wifi'] ?? [];
        $bf = $hotelSettings['breakfast'] ?? [];
        $contact = $hotelSettings['contact'] ?? [];
    @endphp

    @if(($wifi['name'] ?? '') || ($wifi['password'] ?? ''))
        @if($wifiVisible ?? false)
            <div class="card">
                <h2>{{ ___('Wi-Fi') }}</h2>
                @if($wifi['name'] ?? '')<div class="kv"><span>{{ ___('Network') }}</span><b>{{ $wifi['name'] }}</b></div>@endif
                @if($wifi['password'] ?? '')<div class="kv"><span>{{ ___('Password') }}</span><b>{{ $wifi['password'] }}</b></div>@endif
            </div>
        @else
            {{-- Credentials exist but this visitor did not prove room presence: hint only. --}}
            <div class="card">
                <h2>{{ ___('Wi-Fi') }}</h2>
                <p style="margin:0;color:var(--muted);">{{ ___('Scan the QR code in your room to see the Wi-Fi password.') }}</p>
            </div>
        @endif
    @endif

    @if(($bf['hours'] ?? '') || ($bf['cutoff'] ?? ''))
        <div class="card">
            <h2>{{ ___('Breakfast') }}</h2>
            @if($bf['hours'] ?? '')<div class="kv"><span>{{ ___('Hours') }}</span><b>{{ $bf['hours'] }}</b></div>@endif
            @if($bf['cutoff'] ?? '')<div class="kv"><span>{{ ___('Pre-order cutoff') }}</span><b>{{ $bf['cutoff'] }}</b></div>@endif
        </div>
    @endif

    @if($hotelSettings['house_rules'] ?? '')
        <div class="card">
            <h2>{{ ___('House rules') }}</h2>
            <div class="rules">{{ $hotelSettings['house_rules'] }}</div>
        </div>
    @endif

    @if(($contact['phone'] ?? '') || ($contact['whatsapp'] ?? '') || ($contact['note'] ?? ''))
        <div class="card">
            <h2>{{ ___('Reception') }}</h2>
            @if($contact['note'] ?? '')<p style="margin:0 0 8px;">{{ $contact['note'] }}</p>@endif
            @if($contact['phone'] ?? '')<a class="btn" href="tel:{{ preg_replace('/[^0-9+]/', '', $contact['phone']) }}">{{ ___('Call reception') }}</a>@endif
            @if($contact['whatsapp'] ?? '')<a class="btn alt" href="https://wa.me/{{ preg_replace('/[^0-9]/', '', $contact['whatsapp']) }}">{{ ___('WhatsApp') }}</a>@endif
        </div>
    @endif

    <div class="card">
        <h2>{{ ___('Request a service') }}</h2>
        <form class="req" action="{{ route('hotel.guest.request', $store->slug) }}" method="post">
            @csrf
            @if($room)<input type="hidden" name="table" value="{{ $room->key }}">@endif
            <select name="type" required>
                <option value="housekeeping">{{ ___('Housekeeping') }}</option>
                <option value="amenity">{{ ___('Extra towels / amenities') }}</option>
                <option value="maintenance">{{ ___('Maintenance') }}</option>
                <option value="staff_call">{{ ___('Call staff') }}</option>
                <option value="custom">{{ ___('Something else') }}</option>
            </select>
            <textarea name="note" rows="2" placeholder="{{ ___('Add a note (optional)') }}"></textarea>
            <button class="btn" type="submit">{{ ___('Send request') }}</button>
        </form>
    </div>
</div>
</body>
</html>
