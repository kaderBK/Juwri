{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ in_array(app()->getLocale(), ['ar','fa','he','ur']) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ ___('Book a room') }} · {{ $store->title }}</title>
    <style nonce="{{ csp_nonce() }}">
        /* Neutral surfaces + the STORE's accent flow in from the shared bridge
           partial (_partials/hotel-page-brand); the pre-restyle literals stay as
           each var()'s fallback so a store with no accent renders as before. */
        :root { --bg:var(--mbh-bg,#f6f7f9); --card:var(--mbh-surface,#fff); --ink:var(--mbh-ink,#111);
            --muted:var(--mbh-muted,#667); --line:var(--mbh-border,#e6e8ec); --brand:var(--mbh-accent,#0d6efd);
            --brand-ink:var(--mbh-on-accent,#fff); --brand-soft:var(--mbh-accent-soft,#eef);
            --brand-ring:var(--mbh-accent-ring,rgba(13,110,253,.3)); --pos:#137a3a; }
        * { box-sizing: border-box; }
        body { font-family: var(--mbh-font, 'Saudi Riyal', system-ui, -apple-system, "Segoe UI", sans-serif); margin:0; background:var(--bg); color:var(--ink); }
        .wrap { max-width: 640px; margin: 0 auto; padding: 20px 16px 48px; }
        header h1 { font-size: 24px; margin: 4px 0; }
        header .sub { color: var(--muted); font-size: 14px; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:var(--mbh-radius,14px); padding:16px; margin-top:14px; }
        .card h2 { font-size:15px; margin:0 0 12px; text-transform:uppercase; letter-spacing:.04em; color:var(--muted); }
        label.fld { display:block; font-size:13px; color:var(--muted); margin-bottom:6px; font-weight:600; }
        .row { display:flex; gap:12px; flex-wrap:wrap; }
        .row > div { flex:1 1 140px; min-width:0; }
        input, select, textarea { width:100%; padding:10px 12px; font:inherit; font-size:15px; color:var(--ink);
            background:#fff; border:1px solid var(--line); border-radius:var(--mbh-radius-control,10px); }
        input:focus, select:focus, textarea:focus { outline:none; border-color:var(--brand); box-shadow:0 0 0 3px var(--brand-ring); }
        textarea { resize:vertical; min-height:64px; }
        .btn { display:inline-block; width:100%; padding:12px 16px; border:0; border-radius:var(--mbh-radius-control,10px); background:var(--brand);
            color:var(--brand-ink); text-decoration:none; font-weight:700; font-size:15px; cursor:pointer; text-align:center; margin-top:8px; }
        .btn.secondary { background:#eef1f6; color:var(--ink); }
        .types { display:flex; flex-direction:column; gap:10px; }
        .type { display:flex; gap:12px; align-items:flex-start; padding:12px; border:1px solid var(--line); border-radius:var(--mbh-radius,12px); cursor:pointer; }
        .type:has(input:checked) { border-color:var(--brand); background:var(--brand-soft); }
        .type input { width:18px; height:18px; flex:0 0 auto; margin-top:2px; accent-color:var(--brand); }
        .type .info { min-width:0; flex:1 1 auto; }
        .type .info b { display:block; font-size:15px; }
        .type .info small { color:var(--muted); }
        .type .price { white-space:nowrap; font-weight:700; font-variant-numeric:tabular-nums; text-align:end; }
        .type .price small { display:block; color:var(--muted); font-weight:500; }
        .type.soldout { opacity:.55; cursor:not-allowed; }
        .empty { color:var(--muted); font-size:14px; text-align:center; padding:20px 0; }
        .note { color:var(--muted); font-size:13px; margin-top:6px; }
        .flash { padding:12px 14px; border-radius:12px; margin-top:14px; font-size:14px; }
        .flash.err { background:#fdecec; color:#a12; border:1px solid #f5c6cb; }
        .flash.ok { background:#e8f7ee; color:#137a3a; border:1px solid #bfe6cd; }
        .badge { display:inline-block; font-size:12px; font-weight:700; padding:2px 10px; border-radius:999px; background:var(--brand-soft,#eef); color:var(--brand); }
    </style>
    {{-- Store-identity bridge: emits the --mbh-* accent/radius/font tokens the :root
         above consumes. After the page style so its :root can supply the tokens. --}}
    @include('store_templates._partials.hotel-page-brand')
    @include('store_templates._partials.riyal-font')
</head>
<body>
@php
    $cur   = $currency;
    $dec   = currency_decimals($cur);
    $sym   = currency_symbol_for($cur);
    // Position via the store's effective rule (currency_pos + RTL-auto + dirham
    // always sign-first); the sign is bidi-isolated + dirham-spaced so Arabic/RTL
    // renders it on the correct side of the number. [[rtl-currency-symbol-position]]
    $pos   = currency_pos_effective($store->id);
    $sign  = cur_isolate($sym);
    $gap   = currency_symbol_gap($sym);
    $money = function ($n) use ($sign, $gap, $dec, $pos) {
        $num = number_format((float) $n, $dec);
        // A price is an LTR-directional unit: wrap it LTR + isolated so the sign keeps
        // the helper's chosen side (pos=1 = sign-BEFORE, e.g. the new SAR sign U+20C1)
        // instead of the RTL page reordering the bidi-isolated sign to the wrong side.
        return '<bdi dir="ltr">'.($pos === 1 ? $sign.$gap.$num : $num.$gap.$sign).'</bdi>';
    };
@endphp
<div class="wrap">
    <header>
        <div class="sub">{{ $store->title }}</div>
        <h1>{{ ___('Book a room') }}</h1>
    </header>

    @if(session('booking_error'))
        <div class="flash err">{{ session('booking_error') }}</div>
    @endif
    @if(session('booking_success'))
        <div class="flash ok">{{ ___('Your booking request was received.') }} <span class="badge">{{ session('booking_success') }}</span></div>
    @endif

    {{-- Dates & occupancy — a GET form that reloads the page with fresh availability. --}}
    <form class="card" method="get" action="{{ route('hotel.guest.book', $store->slug) }}" id="mbBookDates">
        <h2>{{ ___('Your stay') }}</h2>
        <div class="row">
            <div>
                <label class="fld" for="ci">{{ ___('Check-in') }}</label>
                <input type="date" id="ci" name="check_in" value="{{ $checkIn }}" min="{{ $today }}" required>
            </div>
            <div>
                <label class="fld" for="co">{{ ___('Check-out') }}</label>
                <input type="date" id="co" name="check_out" value="{{ $checkOut }}" min="{{ $checkIn }}" required>
            </div>
        </div>
        <div class="row" style="margin-top:12px;">
            <div>
                <label class="fld" for="ad">{{ ___('Adults') }}</label>
                <select id="ad" name="adults">
                    @for($i = 1; $i <= 8; $i++)<option value="{{ $i }}" @selected((int) request('adults', 2) === $i)>{{ $i }}</option>@endfor
                </select>
            </div>
            <div>
                <label class="fld" for="ch">{{ ___('Children') }}</label>
                <select id="ch" name="children">
                    @for($i = 0; $i <= 8; $i++)<option value="{{ $i }}" @selected((int) request('children', 0) === $i)>{{ $i }}</option>@endfor
                </select>
            </div>
        </div>
        <button type="submit" class="btn secondary">{{ ___('Check availability') }}</button>
        <div class="note">{{ (int) $nights }} {{ (int) $nights === 1 ? ___('night') : ___('nights') }}</div>
    </form>

    {{-- Room-type cards + guest details, posted together. --}}
    <form method="post" action="{{ route('hotel.guest.book.submit', $store->slug) }}" id="mbBookForm">
        @csrf
        <input type="hidden" name="check_in" value="{{ $checkIn }}">
        <input type="hidden" name="check_out" value="{{ $checkOut }}">
        <input type="hidden" name="adults" value="{{ (int) request('adults', 2) }}">
        <input type="hidden" name="children" value="{{ (int) request('children', 0) }}">

        <div class="card">
            <h2>{{ ___('Choose a room') }}</h2>
            @if(!empty($types))
                <div class="types">
                    @foreach($types as $t)
                        @php
                            $tid    = (int) ($t['id'] ?? 0);
                            $tname  = (string) ($t['name'] ?? ___('Room'));
                            $avail  = (int) ($t['available'] ?? 0);
                            $ntotal = $t['nightly_total'] ?? ($t['base_rate'] ?? null);
                            $sold   = $avail <= 0;
                        @endphp
                        <label class="type {{ $sold ? 'soldout' : '' }}">
                            <input type="radio" name="room_type_id" value="{{ $tid }}" @disabled($sold) required>
                            <span class="info">
                                <b>{{ $tname }}</b>
                                @if($sold)
                                    <small>{{ ___('Sold out for these dates') }}</small>
                                @else
                                    <small>{{ $avail }} {{ $avail === 1 ? ___('room left') : ___('rooms left') }}</small>
                                @endif
                            </span>
                            @if($ntotal !== null)
                                <span class="price">{!! $money($ntotal) !!}<small>{{ ___('total') }}</small></span>
                            @endif
                        </label>
                    @endforeach
                </div>
            @else
                <p class="empty">{{ ___('Online booking opens soon. Please check back or contact reception.') }}</p>
            @endif
        </div>

        @if(!empty($types))
        <div class="card">
            <h2>{{ ___('Your details') }}</h2>
            <label class="fld" for="gn">{{ ___('Full name') }}</label>
            <input type="text" id="gn" name="guest_name" value="{{ old('guest_name') }}" maxlength="120" required>
            <label class="fld" for="gp" style="margin-top:12px;">{{ ___('Phone') }}</label>
            <input type="text" id="gp" name="guest_phone" value="{{ old('guest_phone') }}" maxlength="40">
            <label class="fld" for="gnote" style="margin-top:12px;">{{ ___('Notes (optional)') }}</label>
            <textarea id="gnote" name="notes" maxlength="2000">{{ old('notes') }}</textarea>
            <button type="submit" class="btn">
                {{ $mode === 'instant' ? ___('Book now') : ___('Request booking') }}
            </button>
            <div class="note">
                {{ $mode === 'instant'
                    ? ___('Your room is confirmed instantly. Pay at the hotel.')
                    : ___('We will confirm your request shortly. No payment now.') }}
            </div>
        </div>
        @endif
    </form>
</div>

<script nonce="{{ csp_nonce() }}">
(function () {
    var ci = document.getElementById('ci');
    var co = document.getElementById('co');
    if (ci && co) {
        // Check-out must stay strictly after check-in.
        ci.addEventListener('change', function () {
            co.min = ci.value;
            if (co.value <= ci.value) {
                var d = new Date(ci.value);
                d.setDate(d.getDate() + 1);
                co.value = d.toISOString().slice(0, 10);
            }
        });
    }
})();
</script>
</body>
</html>
