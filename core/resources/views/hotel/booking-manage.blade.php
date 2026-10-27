{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ in_array(app()->getLocale(), ['ar','fa','he','ur']) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- A guest's booking is private: never index it. --}}
    <meta name="robots" content="noindex, nofollow">
    <title>{{ ___('Your booking') }} · {{ $store->title }}</title>
    <style nonce="{{ csp_nonce() }}">
        /* Neutral surfaces + the STORE's accent flow in from the shared bridge
           partial (_partials/hotel-page-brand); pre-restyle literals stay as each
           var()'s fallback. Status chips below keep their OWN semantic colours —
           the accent lands only on the booking reference. */
        :root { --bg:var(--mbh-bg,#f6f7f9); --card:var(--mbh-surface,#fff); --ink:var(--mbh-ink,#111);
            --muted:var(--mbh-muted,#667); --line:var(--mbh-border,#e6e8ec); --brand:var(--mbh-accent,#0d6efd); }
        * { box-sizing: border-box; }
        body { font-family: var(--mbh-font, 'Saudi Riyal', system-ui, -apple-system, "Segoe UI", sans-serif); margin:0; background:var(--bg); color:var(--ink); }
        .wrap { max-width: 560px; margin: 0 auto; padding: 20px 16px 48px; }
        header h1 { font-size: 22px; margin: 4px 0; }
        header .sub { color: var(--muted); font-size: 14px; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:var(--mbh-radius,14px); padding:16px; margin-top:14px; }
        .code { text-align:center; }
        .code .ref { font-size:30px; font-weight:800; letter-spacing:.06em; font-variant-numeric:tabular-nums; color:var(--brand); }
        .code .lbl { color:var(--muted); font-size:13px; }
        .meta { display:flex; justify-content:space-between; gap:12px; color:var(--muted); font-size:14px; padding:6px 0; border-top:1px solid var(--line); }
        .meta:first-child { border-top:0; }
        .meta b { color:var(--ink); font-weight:600; }
        .chip { display:inline-block; font-size:12px; font-weight:700; padding:3px 12px; border-radius:999px; }
        .chip.pending   { background:#fff4e0; color:#b45309; }
        .chip.confirmed { background:#e8f7ee; color:#137a3a; }
        /* Semantic blue, pinned literal so remapping --brand to the store accent
           does not bleed into a status chip (status chips stay semantic). */
        .chip.checked_in{ background:#eef; color:#0d6efd; }
        .chip.checked_out,.chip.cancelled,.chip.no_show { background:#f1f1f4; color:var(--muted); }
        .btn { display:inline-block; width:100%; padding:12px 16px; border:0; border-radius:var(--mbh-radius-control,10px); background:#fdecec; color:#a12;
            font-weight:700; font-size:15px; cursor:pointer; text-align:center; }
        .note { color:var(--muted); font-size:13px; margin-top:8px; text-align:center; }
        .flash { padding:12px 14px; border-radius:12px; margin-top:14px; font-size:14px; }
        .flash.err { background:#fdecec; color:#a12; border:1px solid #f5c6cb; }
        .flash.ok { background:#e8f7ee; color:#137a3a; border:1px solid #bfe6cd; }
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
        // LTR-isolated money unit so the sign keeps the helper's side (pos=1 = before,
        // e.g. the new SAR sign) instead of the RTL page reordering it. See booking.blade.
        return '<bdi dir="ltr">'.($pos === 1 ? $sign.$gap.$num : $num.$gap.$sign).'</bdi>';
    };
    $status = (string) $booking->status;
    $statusLabels = [
        'pending'      => ___('Awaiting confirmation'),
        'confirmed'    => ___('Confirmed'),
        'checked_in'   => ___('Checked in'),
        'checked_out'  => ___('Checked out'),
        'cancelled'    => ___('Cancelled'),
        'no_show'      => ___('No show'),
    ];
    $fmt = function ($d) { try { return \Carbon\Carbon::parse($d)->isoFormat('ddd D MMM YYYY'); } catch (\Throwable $e) { return (string) $d; } };
@endphp
<div class="wrap">
    <header>
        <div class="sub">{{ $store->title }}</div>
        <h1>{{ ___('Your booking') }}</h1>
    </header>

    @if(session('booking_cancelled'))
        <div class="flash ok">{{ ___('Your booking has been cancelled.') }}</div>
    @endif
    @if(session('booking_error'))
        <div class="flash err">{{ session('booking_error') }}</div>
    @endif

    <div class="card code">
        <div class="lbl">{{ ___('Booking reference') }}</div>
        <div class="ref">{{ $booking->code }}</div>
        <div style="margin-top:8px;"><span class="chip {{ $status }}">{{ $statusLabels[$status] ?? $status }}</span></div>
    </div>

    <div class="card">
        <div class="meta"><span>{{ ___('Room type') }}</span><b>{{ $typeName }}</b></div>
        <div class="meta"><span>{{ ___('Check-in') }}</span><b>{{ $fmt($booking->check_in) }}</b></div>
        <div class="meta"><span>{{ ___('Check-out') }}</span><b>{{ $fmt($booking->check_out) }}</b></div>
        <div class="meta"><span>{{ ___('Guests') }}</span><b>{{ (int) $booking->adults }}@if((int) $booking->children > 0) + {{ (int) $booking->children }} {{ ___('children') }}@endif</b></div>
        @if($booking->quoted_total !== null)
            <div class="meta"><span>{{ ___('Estimated total') }}</span><b>{!! $money($booking->quoted_total) !!}</b></div>
        @endif
        <div class="meta"><span>{{ ___('Guest') }}</span><b>{{ $booking->guest_name }}</b></div>
    </div>

    @if($cancellable)
    <div class="card">
        <form method="post" action="{{ route('hotel.guest.booking.cancel', ['slug' => $store->slug, 'token' => $booking->token]) }}">
            @csrf
            <button type="submit" class="btn">{{ ___('Cancel this booking') }}</button>
        </form>
        <div class="note">{{ ___('Free cancellation is available up to the deadline set by the hotel.') }}</div>
    </div>
    @endif
</div>
</body>
</html>
