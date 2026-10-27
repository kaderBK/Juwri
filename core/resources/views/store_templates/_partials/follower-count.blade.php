{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    BRAND-ROW B1+B2 — shared proof line for the F2 brand/follow row.
    Expects: $store, $followerCount (int). Optional: $scansToday (int, live viewers).
    Renders the compact count and/or "N viewing now" on ONE line (single source of
    proof — the viewers chip is removed from the status strip), else the value-copy
    CTA. KEEP id="cvFollowerCount" on the number span — theme JS bumps it on follow (B5).
--}}
@php
    // B11: the owner can hide the follower NUMBER (customizer toggle, default on).
    // Each theme's blade reads its own brand.show_follower_count and passes it here
    // (the read stays per-theme; this shared partial must not call theme_setting or
    // the schema lint attributes the key to every theme). Off → never the count.
    $fcCountAllowed = ($showFollowerCount ?? true);
    $fcShowCount = $fcCountAllowed && $followerCount >= follower_count_min();
    $fcViewers = (int) ($scansToday ?? 0);
    $fcShowViewers = $fcViewers > 0;
    // Each theme's JS bumps its own count span id (crave cvFollowerCount, aurora
    // au-follower-count, …). Default keeps crave unchanged; ports pass their own.
    $fcCountId = $countId ?? 'cvFollowerCount';
@endphp
@if($fcShowCount || $fcShowViewers)
    <small class="brand-followers">
        @if($fcShowCount)<span id="{{ $fcCountId }}">{{ format_follower_count($followerCount) }}</span> {{ ___('followers') }}@endif
        @if($fcShowCount && $fcShowViewers)<span class="brand-dot" aria-hidden="true"> · </span>@endif
        @if($fcShowViewers)<span class="brand-viewers"><b>{{ $fcViewers }}</b> {{ ___('viewing now') }}</span>@endif
    </small>
@else
    <small class="brand-followers brand-followers--cta">{{ ___('Follow for offers & new dishes') }}</small>
@endif
