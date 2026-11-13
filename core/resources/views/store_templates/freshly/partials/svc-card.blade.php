{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- freshly — one "service" card (a top-level menu category with bookable
     items inside it). Shared by the home screen and the full services list
     so both stay byte-identical instead of two hand-maintained copies.
     Expects: $svc (from $jsServices in index.blade.php), $T (string table).
     .frs-svc-cardwrap is a new positioning wrapper (curated core feature:
     wishlist) around the SAME existing <a class="frs-svc-card"> — nothing
     inside that anchor is renamed/removed; the heart button is a sibling so
     it never nests inside the anchor's own click target. data-name feeds the
     Services screen's client-side search filter. --}}
<div class="frs-svc-cardwrap" data-name="{{ mb_strtolower($svc['name']) }}">
    <a href="?service={{ $svc['id'] }}" class="frs-svc-card" data-open-service="{{ $svc['id'] }}">
        <span class="frs-svc-thumb">
            @if($svc['img'])
                @if((string) ($svc['img']) !== '')<img src="{{ $svc['img'] }}" alt="" loading="lazy" data-csp-error="hide">@endif
            @else
                <span class="frs-svc-thumb-ph" role="img" aria-label="{{ $svc['name'] }}">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M12 3s7 8 7 13a7 7 0 01-14 0c0-5 7-13 7-13z"/></svg>
                </span>
            @endif
        </span>
        <span class="frs-svc-body">
            <span class="frs-svc-name">{{ $svc['name'] }}</span>
            @if($svc['desc'])<span class="frs-svc-desc">{{ $svc['desc'] }}</span>@endif
            <span class="frs-svc-price">{{ $T['from'] }} {{ $svc['fromPriceFormatted'] }}/{{ $svc['unitLabel'] }}</span>
        </span>
        <span class="frs-svc-action" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
        </span>
    </a>
    <button type="button" class="frs-fav-btn" data-fav-toggle="{{ $svc['id'] }}" aria-pressed="false" aria-label="{{ $T['save'] }}">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s-7.5-4.6-10-9.1C.4 8.4 2 5 5.4 5c2 0 3.4 1 4.6 2.6C11.2 6 12.6 5 14.6 5 18 5 19.6 8.4 18 11.9 15.5 16.4 12 21 12 21Z"/></svg>
    </button>
</div>
