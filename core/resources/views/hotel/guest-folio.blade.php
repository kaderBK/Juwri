{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ in_array(app()->getLocale(), ['ar','fa','he','ur']) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- A guest's bill is private: never index it. --}}
    <meta name="robots" content="noindex, nofollow">
    <title>{{ ___('Your bill') }} · {{ $store->title }}</title>
    <style nonce="{{ csp_nonce() }}">
        /* Neutral surfaces + the STORE's accent flow in from the shared bridge
           partial (_partials/hotel-page-brand); pre-restyle literals stay as each
           var()'s fallback. --pos (payments) stays a fixed semantic green. */
        :root { --bg:var(--mbh-bg,#f6f7f9); --card:var(--mbh-surface,#fff); --ink:var(--mbh-ink,#111);
            --muted:var(--mbh-muted,#667); --line:var(--mbh-border,#e6e8ec); --brand:var(--mbh-accent,#0d6efd);
            --brand-ink:var(--mbh-on-accent,#fff); --brand-soft:var(--mbh-accent-soft,#eef); --pos:#137a3a; }
        * { box-sizing: border-box; }
        body { font-family: var(--mbh-font, 'Saudi Riyal', system-ui, -apple-system, "Segoe UI", sans-serif); margin:0; background:var(--bg); color:var(--ink); }
        .wrap { max-width: 560px; margin: 0 auto; padding: 20px 16px 48px; }
        header h1 { font-size: 22px; margin: 4px 0; }
        header .room { color: var(--muted); font-size: 14px; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:var(--mbh-radius,14px); padding:16px; margin-top:14px; }
        .card h2 { font-size:15px; margin:0 0 10px; text-transform:uppercase; letter-spacing:.04em; color:var(--brand); }
        .bal { text-align:center; }
        .bal .amt { font-size:34px; font-weight:800; letter-spacing:-.02em; color:var(--brand); }
        .bal .lbl { color:var(--muted); font-size:13px; margin-bottom:4px; }
        .bal .note { color:var(--muted); font-size:13px; margin-top:10px; line-height:1.5; }
        .line { display:flex; justify-content:space-between; gap:12px; padding:9px 0; border-top:1px solid var(--line); }
        .line:first-of-type { border-top:0; }
        .line .desc { min-width:0; }
        .line .desc b { font-weight:600; display:block; }
        .line .desc small { color:var(--muted); }
        .line .val { white-space:nowrap; font-variant-numeric:tabular-nums; font-weight:600; }
        .line .val.pay { color:var(--pos); }
        .totals { margin-top:6px; }
        .totals .line { font-size:14px; }
        .totals .line.grand b { font-weight:800; }
        .meta { display:flex; justify-content:space-between; gap:12px; color:var(--muted); font-size:13px; padding:3px 0; }
        .badge { display:inline-block; font-size:12px; font-weight:700; padding:2px 10px; border-radius:999px; background:var(--brand-soft,#eef); color:var(--brand); }
        .badge.out { background:#f1f1f4; color:var(--muted); }
        .btn { display:inline-block; padding:10px 16px; border-radius:var(--mbh-radius-control,10px); background:var(--brand); color:var(--brand-ink); text-decoration:none; font-weight:600; margin-top:14px; }
        .empty { color:var(--muted); font-size:14px; }
    </style>
    {{-- Store-identity bridge: emits the --mbh-* accent/radius/font tokens the :root
         above consumes. After the page style so its :root can supply the tokens. --}}
    @include('store_templates._partials.hotel-page-brand')
    @include('store_templates._partials.riyal-font')
</head>
<body>
@php
    $cur  = $statement['currency'];
    $dec  = currency_decimals($cur);
    $sym  = currency_symbol_for($cur);
    /* Position via the store's effective rule (currency_pos + RTL-auto + dirham
       always sign-first); the sign is bidi-isolated + dirham-spaced so Arabic/RTL
       renders it on the correct side of the number. [[rtl-currency-symbol-position]] */
    $pos  = currency_pos_effective($store->id);
    $sign = cur_isolate($sym);
    $gap  = currency_symbol_gap($sym);
    $money = function ($n) use ($sign, $gap, $dec, $pos) {
        $neg = (float) $n < 0;
        $num = number_format(abs((float) $n), $dec);
        // LTR-isolated money unit so the sign keeps the helper's side (pos=1 = before,
        // e.g. the new SAR sign) instead of the RTL page reordering it. See booking.blade.
        // A negative amount carries its minus INSIDE the bdi (leftmost), so it reads as
        // one token (−⃁450.00); a minus left outside detaches to the wrong side in RTL.
        $body = $pos === 1 ? $sign.$gap.$num : $num.$gap.$sign;
        return '<bdi dir="ltr">'.($neg ? '−' : '').$body.'</bdi>';
    };
    $roomLabel = $room ? ($room->label ?: (___('Room').' '.$room->table_no)) : (___('Room').' '.$statement['room']);
    $active    = $stay->status === 'checked_in';
@endphp
<div class="wrap">
    <header>
        <div class="room">
            {{ $roomLabel }}
            @if($active)
                <span class="badge">{{ ___('Checked in') }}</span>
            @else
                <span class="badge out">{{ ___('Checked out') }}</span>
            @endif
        </div>
        <h1>{{ ___('Your bill') }}</h1>
    </header>

    {{-- Stay meta --}}
    <div class="card">
        <div class="meta"><span>{{ ___('Guest') }}</span><b>{{ $stay->guest_name }}</b></div>
        @if($stay->checked_in_at)
            <div class="meta"><span>{{ ___('Checked in') }}</span><span>{{ $stay->checked_in_at->isoFormat('D MMM YYYY, HH:mm') }}</span></div>
        @endif
        @if($stay->checked_out_at)
            <div class="meta"><span>{{ ___('Checked out') }}</span><span>{{ $stay->checked_out_at->isoFormat('D MMM YYYY, HH:mm') }}</span></div>
        @endif
    </div>

    {{-- Balance --}}
    <div class="card bal">
        <div class="lbl">{{ ___('Balance due') }}</div>
        <div class="amt">{!! $money($statement['balance']) !!}</div>
        <div class="note">{{ ___('This is what is currently on your room bill. Settle it at reception at check-out.') }}</div>
    </div>

    {{-- Charges, grouped (C3). Room-night lines and hotel-service lines carry structured
         ledger refs (`hotel_folio:night:…` / `hotel_svc:…`); $folioRefs maps each line id to
         its ref so we can bucket the flat statement rows into a Room/nights group, a Services
         group and everything else. When the refs are unavailable every line falls into
         "Charges", i.e. the pre-C3 flat list. --}}
    @php
        $folioRefs = $folioRefs ?? [];
        $nightLines = $serviceLines = $otherLines = [];
        foreach ($statement['charges'] as $c) {
            $ref = (string) ($folioRefs[$c['id']] ?? '');
            if (str_starts_with($ref, 'hotel_folio:night:')) {
                $parts = explode(':', $ref);
                $c['night_date'] = $parts[3] ?? null;
                $c['is_rev']     = ($parts[4] ?? '') === 'rev';
                $nightLines[] = $c;
            } elseif (str_starts_with($ref, 'hotel_svc:')) {
                $serviceLines[] = $c;
            } else {
                $otherLines[] = $c;
            }
        }
        $dateLabel = function ($d) {
            try { return \Carbon\Carbon::parse($d)->isoFormat('ddd D MMM'); } catch (\Throwable $e) { return (string) $d; }
        };
    @endphp

    @if(!empty($nightLines))
    <div class="card">
        <h2>{{ ___('Room') }}</h2>
        @foreach($nightLines as $c)
            <div class="line">
                <div class="desc">
                    <b>{{ $c['reason'] ?: ___('Room charge') }}</b>
                    @if(!empty($c['night_date']))<small>{{ $dateLabel($c['night_date']) }}@if(!empty($c['is_rev'])) · {{ ___('adjustment') }}@endif</small>@endif
                </div>
                <div class="val">{!! $money($c['amount']) !!}</div>
            </div>
        @endforeach
    </div>
    @endif

    @if(!empty($serviceLines))
    <div class="card">
        <h2>{{ ___('Services') }}</h2>
        @foreach($serviceLines as $c)
            <div class="line">
                <div class="desc">
                    <b>{{ $c['reason'] ?: ___('Service') }}</b>
                    @if($c['at'])<small>{{ $c['at']->isoFormat('D MMM, HH:mm') }}</small>@endif
                </div>
                <div class="val">{!! $money($c['amount']) !!}</div>
            </div>
        @endforeach
    </div>
    @endif

    {{-- Everything else (room-service orders, tray charge, manual charges). --}}
    <div class="card">
        <h2>{{ ___('Charges') }}</h2>
        @forelse($otherLines as $c)
            <div class="line">
                <div class="desc">
                    <b>{{ $c['reason'] ?: ___('Charge') }}</b>
                    @if($c['order_id'])<small>{{ ___('Order') }} #{{ $c['order_id'] }}</small>@endif
                    @if($c['at'])<small>{{ $c['at']->isoFormat('D MMM, HH:mm') }}</small>@endif
                </div>
                <div class="val">{!! $money($c['amount']) !!}</div>
            </div>
        @empty
            @if(empty($nightLines) && empty($serviceLines))
                <p class="empty">{{ ___('No charges yet.') }}</p>
            @else
                <p class="empty">{{ ___('No other charges.') }}</p>
            @endif
        @endforelse
    </div>

    {{-- Payments --}}
    @if(!empty($statement['payments']))
    <div class="card">
        <h2>{{ ___('Payments') }}</h2>
        @foreach($statement['payments'] as $p)
            <div class="line">
                <div class="desc">
                    <b>{{ $p['reason'] ?: ___('Payment') }}</b>
                    @if($p['at'])<small>{{ $p['at']->isoFormat('D MMM, HH:mm') }}</small>@endif
                </div>
                <div class="val pay">{!! $money(-$p['amount']) !!}</div>
            </div>
        @endforeach
    </div>
    @endif

    {{-- Totals --}}
    <div class="card totals">
        <div class="line"><span>{{ ___('Total charges') }}</span><span class="val">{!! $money($statement['total_charges']) !!}</span></div>
        <div class="line"><span>{{ ___('Total payments') }}</span><span class="val pay">{!! $money(-$statement['total_payments']) !!}</span></div>
        <div class="line grand"><b>{{ ___('Balance due') }}</b><b class="val">{!! $money($statement['balance']) !!}</b></div>
    </div>

    @if($active && $room && $room->key)
        <a class="btn" href="{{ route('publicView', $store->slug) }}?table={{ $room->key }}">{{ ___('Order to your room') }}</a>
    @endif
</div>
</body>
</html>
