{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<!DOCTYPE html>
{{-- stores P1-5: public Theme Store browse. Self-contained (no dashboard/theme
     layout deps), linen palette, RTL via logical CSS. Faceted filter rail with
     live counts + zero-hit disabled, search, sort, card grid. Refs: Coda
     integrations (rail+counts) / Zendesk marketplace (result-count header). --}}
@php $rtl = function_exists('current_language') && current_language() ? current_language()->direction === 'rtl' : false; @endphp
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ ___('Theme Store') }} — {{ $siteName }}</title>
    <meta name="description" content="{{ ___('Browse restaurant storefront themes — bold, minimal, cafe, fine dining and more, built on :site.', ['site' => $siteName]) }}">
    <meta property="og:title" content="{{ ___('Theme Store') }} — {{ $siteName }}">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ route('marketplace.index') }}">
    <link rel="canonical" href="{{ route('marketplace.index') }}">
    <style nonce="{{ csp_nonce() }}">
        :root {
            --bg:#f9fafb; --panel:#ffffff; --ink:#1f1c2b; --muted:#6c6b70;
            --line:#e7e6ee; --accent:#8b3dff; --accent-ink:#6524cc; --star:#ffb400; --ok:#198754;
        }
        * { box-sizing:border-box; }
        body { margin:0; background:var(--bg); color:var(--ink);
            font:16px/1.6 'Instrument Sans',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; }
        a { color:inherit; text-decoration:none; }
        .wrap { max-width:1180px; margin:0 auto; padding-inline:24px; }
        header.top { padding-block:44px 8px; }
        h1 { font-family:'Syne','Instrument Sans',sans-serif; font-size:34px; margin:0 0 6px; letter-spacing:-.01em; }
        header.top p { color:var(--muted); margin:0; max-width:60ch; }

        .layout { display:grid; grid-template-columns:250px 1fr; gap:32px; padding-block:28px 80px; align-items:start; }
        @media (max-width:820px){ .layout{ grid-template-columns:1fr; } }

        /* ── Filters: a sticky sidebar on desktop, a bottom sheet on phones ── */
        aside.rail { position:sticky; top:82px; background:var(--panel); border:1px solid var(--line);
            border-radius:16px; padding:20px; }
        .rhead { display:flex; align-items:center; justify-content:space-between; margin-bottom:8px; }
        .rhead h2 { font-size:13px; text-transform:uppercase; letter-spacing:.08em; color:var(--muted); margin:0; }
        .rail .clear { font-size:13px; color:var(--accent); }
        .fcount { background:var(--accent); color:#fff; font-size:12px; font-weight:700; border-radius:999px;
            min-width:19px; height:19px; display:inline-grid; place-items:center; padding:0 5px; }
        /* mobile-only sheet chrome — hidden on desktop */
        .sheet-head, .sheet-foot, .sheet-backdrop, .filterbtn, .chips { display:none; }

        @media (max-width:820px){
            /* trigger button + removable chips sit above the grid */
            .filterbtn { display:inline-flex; align-items:center; gap:8px; cursor:pointer; border:1px solid var(--line);
                background:var(--panel); color:var(--ink); font:inherit; font-weight:600; font-size:14px;
                padding:10px 16px; border-radius:999px; }
            .filterbtn svg { width:17px; height:17px; }
            .chips { display:flex; flex-wrap:wrap; gap:8px; margin:0 0 18px; }
            .chip { display:inline-flex; align-items:center; gap:6px; font-size:13px; font-weight:600; color:var(--ink);
                background:var(--panel); border:1px solid var(--line); border-radius:999px; padding:5px 12px; }
            .chip span { color:var(--muted); font-size:13px; }
            .chip-clear { color:var(--accent); border-color:transparent; background:transparent; }

            /* the rail turns into a bottom sheet */
            aside.rail { position:fixed; inset-inline:0; bottom:0; top:auto; z-index:60; width:auto; max-height:86vh;
                overflow-y:auto; border:0; border-radius:20px 20px 0 0; padding:0 20px 20px;
                transform:translateY(100%); transition:transform .28s cubic-bezier(.4,0,.2,1);
                box-shadow:0 -18px 50px rgba(23,15,46,.22); }
            aside.rail.is-open { transform:translateY(0); }
            .rhead { display:none; }
            .sheet-head { display:flex; align-items:center; justify-content:space-between; gap:12px; position:sticky;
                top:0; background:var(--panel); padding:16px 0 12px; border-bottom:1px solid var(--line);
                font-size:17px; font-weight:800; z-index:1; }
            .sheet-x { border:0; background:var(--bg); color:var(--ink); width:34px; height:34px; border-radius:50%;
                font-size:19px; line-height:1; cursor:pointer; flex:none; }
            .sheet-foot { display:flex; align-items:center; gap:14px; position:sticky; bottom:0; background:var(--panel);
                padding:14px 0 4px; margin-top:6px; border-top:1px solid var(--line); }
            .sheet-foot .clear { flex:none; }
            .applybtn { flex:1; border:0; cursor:pointer; background:var(--accent); color:#fff; font:inherit;
                font-weight:700; font-size:15px; padding:13px; border-radius:999px; }
            .sheet-backdrop { display:block; position:fixed; inset:0; z-index:59; background:rgba(15,10,30,.42);
                opacity:0; visibility:hidden; transition:opacity .28s, visibility .28s; }
            .sheet-backdrop.is-open { opacity:1; visibility:visible; }
            body.sheet-lock { overflow:hidden; }
        }
        @media (prefers-reduced-motion:reduce){ aside.rail, .sheet-backdrop { transition:none; } }
        .fgroup { border-top:1px solid var(--line); padding-block:14px; }
        .fgroup > .flabel { font-weight:600; font-size:14px; margin-bottom:8px; }
        .frow { display:flex; align-items:center; gap:9px; padding-block:4px; font-size:14.5px; cursor:pointer; }
        .frow input { accent-color:var(--accent); }
        .frow.disabled { color:var(--muted); cursor:not-allowed; opacity:.55; }
        .frow .cnt { margin-inline-start:auto; color:var(--muted); font-size:12.5px;
            background:var(--bg); border-radius:999px; padding:1px 8px; }

        .main .bar { display:flex; align-items:center; gap:16px; flex-wrap:wrap; margin-bottom:20px; }
        .count { font-weight:600; }
        .search { flex:1; min-width:200px; }
        .search input { width:100%; padding:11px 16px; border-radius:999px; border:1px solid var(--line);
            background:var(--panel); color:var(--ink); font:inherit; }
        .sort select { padding:10px 14px; border-radius:10px; border:1px solid var(--line);
            background:var(--panel); color:var(--ink); font:inherit; }

        .grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(250px,1fr)); gap:22px; }
        .card { background:var(--panel); border:1px solid var(--line); border-radius:16px; overflow:hidden;
            transition:border-color .15s, box-shadow .15s, transform .15s; display:flex; flex-direction:column; }
        .card:hover { border-color:var(--accent); box-shadow:0 8px 26px rgba(23,15,46,.10); transform:translateY(-2px); }
        .card .thumb { aspect-ratio:16/10; background:var(--chip,#efe7d7) center/cover no-repeat; display:grid;
            place-items:center; color:var(--muted); font-size:13px; }
        /* A screenshot theme shows its storefront in a small, fully-visible iPhone
           standing on a soft stage — same idea as the Bazaar card's phone mock. */
        .card .thumb--phone { overflow:hidden; background:linear-gradient(165deg,#efe7d7,#e2d6bf); }
        .card .thumb--phone .iphone { display:block; position:relative; height:132px; aspect-ratio:480/987;
            background:#0f0f10; border-radius:14px; padding:3px; box-shadow:0 8px 20px rgba(0,0,0,.28); }
        .card .thumb--phone .iphone::before { content:""; position:absolute; top:3px; left:50%; transform:translateX(-50%);
            width:24px; height:6px; background:#0f0f10; border-radius:0 0 6px 6px; z-index:2; }
        .card .thumb--phone .iphone img { display:block; width:100%; height:100%; object-fit:cover;
            object-position:top center; border-radius:11px; }
        .card .body { padding:14px 16px 16px; }
        .card .name { font-weight:700; font-size:16px; }
        .card .seller { color:var(--muted); font-size:13px; margin-top:1px; }
        .card .meta { display:flex; align-items:center; justify-content:space-between; margin-top:12px; }
        .price { color:var(--accent-ink); font-weight:700; }
        .price.free { color:var(--ok,#198754); }
        .rating { font-size:13px; color:var(--muted); display:inline-flex; align-items:center; gap:4px; }
        .rating .stars { color:var(--star); letter-spacing:1px; }
        .badge-new { font-size:11.5px; font-weight:600; color:var(--accent); border:1px solid var(--line);
            border-radius:999px; padding:1px 8px; }
        .badge-builtin { position:absolute; top:10px; inset-inline-start:10px; z-index:1; font-size:11px; font-weight:600;
            color:#fff; background:rgba(17,17,17,.72); border-radius:999px; padding:2px 9px; backdrop-filter:blur(2px); }
        .card { position:relative; }
        .empty { color:var(--muted); text-align:center; padding:70px 0; grid-column:1/-1; }
        .devlink { color:var(--muted); font-size:14px; padding-block:0 60px; }
        .devlink a { color:var(--accent); }
    </style>
    {{-- Saudi Riyal (U+20C1) / Dirham (U+20C3) glyph font, so store prices in SAR/AED render the sign instead of a tofu box. --}}
    @include('partials.riyal-font')
    <script src="{{ asset('assets/js/csp-actions.js') }}?v={{ filemtime(public_path('assets/js/csp-actions.js')) }}"></script>
</head>
<body>
@include('apps.store._nav', ['asNavActive' => 'themes'])
@php
    $hasFilters = $q !== '' || $price !== '' || !empty($industry) || $themeSet !== null;
@endphp
<div class="wrap">
    <header class="top">
        <h1>{{ ___('Theme Store') }}</h1>
        <p>{{ ___('Find a theme built for your :noun', ['noun' => store_noun(null, 'poss')]) }}</p>
    </header>

    <form method="get" action="{{ route('marketplace.index') }}" id="browse">
    <div class="layout">
        {{-- Filter rail. Desktop: a sticky sidebar (facets submit on change). Phones:
             a bottom sheet opened from the "Filters" button; facets are batched and
             committed with "Show results" so the page doesn't reload on every tap. --}}
        @php
            $activeFilters = ($price !== '' ? 1 : 0) + count($industry) + ((int) $minRating > 0 ? 1 : 0) + ($themeSet !== null ? 1 : 0);
        @endphp
        <div class="sheet-backdrop" data-close aria-hidden="true"></div>
        <aside class="rail" id="filterRail" aria-label="{{ ___('Filters') }}">
            <div class="sheet-head">
                <span>{{ ___('Filters') }}</span>
                <button type="button" class="sheet-x" data-close aria-label="{{ ___('Close') }}">&times;</button>
            </div>
            <div class="rhead">
                <h2>{{ ___('Filters') }}</h2>
                @if($hasFilters)<a class="clear" href="{{ route('marketplace.index') }}">{{ ___('Clear all') }}</a>@endif
            </div>

            {{-- q + sort live in the main column (same form); no dupes here. --}}

            <div class="fgroup">
                <div class="flabel">{{ ___('Price') }}</div>
                <label class="frow">
                    <input type="radio" name="price" value="" @checked($price === '') data-change="facetChanged" data-this>
                    <span>{{ ___('Featured') }}</span><span class="cnt">{{ $freeCount + $paidCount }}</span>
                </label>
                <label class="frow {{ $freeCount === 0 ? 'disabled' : '' }}">
                    <input type="radio" name="price" value="free" @checked($price === 'free') @disabled($freeCount === 0) data-change="facetChanged" data-this>
                    <span>{{ ___('Free') }}</span><span class="cnt">{{ $freeCount }}</span>
                </label>
                <label class="frow {{ $paidCount === 0 ? 'disabled' : '' }}">
                    <input type="radio" name="price" value="paid" @checked($price === 'paid') @disabled($paidCount === 0) data-change="facetChanged" data-this>
                    <span>{{ ___('Paid') }}</span><span class="cnt">{{ $paidCount }}</span>
                </label>
            </div>

            <div class="fgroup">
                <div class="flabel">{{ ___('store_type') }}</div>
                <label class="frow">
                    <input type="radio" name="set" value="" @checked($themeSet === null) data-change="facetChanged" data-this>
                    <span>{{ ___('Any') }}</span><span class="cnt">{{ array_sum($setCounts) }}</span>
                </label>
                @foreach($themeSets as $setKey => $setLabel)
                    @php $sc = $setCounts[$setKey] ?? 0; @endphp
                    <label class="frow {{ ($sc === 0 && $themeSet !== $setKey) ? 'disabled' : '' }}">
                        <input type="radio" name="set" value="{{ $setKey }}" @checked($themeSet === $setKey) @disabled($sc === 0 && $themeSet !== $setKey) data-change="facetChanged" data-this>
                        <span>{{ $setLabel }}</span><span class="cnt">{{ $sc }}</span>
                    </label>
                @endforeach
            </div>

            <div class="fgroup">
                <div class="flabel">{{ ___('Industry') }}</div>
                @foreach($industries as $key)
                    @php $c = $industryCounts[$key]; $checked = in_array($key, $industry, true); @endphp
                    <label class="frow {{ ($c === 0 && !$checked) ? 'disabled' : '' }}">
                        <input type="checkbox" name="industry[]" value="{{ $key }}" @checked($checked) @disabled($c === 0 && !$checked) data-change="facetChanged" data-this>
                        <span>{{ ___(ucfirst(str_replace('_',' ',$key))) }}</span><span class="cnt">{{ $c }}</span>
                    </label>
                @endforeach
            </div>

            <div class="fgroup">
                <div class="flabel">{{ ___('Rating') }}</div>
                <label class="frow">
                    <input type="radio" name="min_rating" value="0" @checked((int) $minRating === 0) data-change="facetChanged" data-this>
                    <span>{{ ___('Any') }}</span>
                </label>
                @foreach([4, 3, 2] as $n)
                    <label class="frow">
                        <input type="radio" name="min_rating" value="{{ $n }}" @checked((int) $minRating === $n) data-change="facetChanged" data-this>
                        <span style="color:var(--star);letter-spacing:1px;">{{ str_repeat('★', $n) }}</span><span style="color:var(--muted);">{{ ___('& up') }}</span>
                    </label>
                @endforeach
            </div>

            <div class="sheet-foot">
                <a class="clear" href="{{ route('marketplace.index') }}">{{ ___('Clear all') }}</a>
                <button type="submit" class="applybtn">{{ ___('Show results') }}</button>
            </div>
        </aside>

        {{-- Main column --}}
        <div class="main">
            <div class="bar">
                <button type="button" class="filterbtn" data-open-filters>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon></svg>
                    {{ ___('Filters') }}@if($activeFilters > 0)<span class="fcount">{{ $activeFilters }}</span>@endif
                </button>
                <span class="count">{{ ___(':count themes', ['count' => $total]) }}</span>
                <div class="search" style="flex:1;min-width:200px;">
                    <input type="search" name="q" value="{{ $q }}" placeholder="{{ ___('Search themes') }}"
                           aria-label="{{ ___('Search themes') }}" data-search="submitBrowse">
                </div>
                <div class="sort">
                    <label class="visually-hidden" for="sortsel">{{ ___('Sort by') }}</label>
                    <select id="sortsel" name="sort" data-change="submitBrowse">
                        <option value="featured" @selected($sort==='featured')>{{ ___('Featured') }}</option>
                        <option value="price_low" @selected($sort==='price_low')>{{ ___('Price: low to high') }}</option>
                        <option value="price_high" @selected($sort==='price_high')>{{ ___('Price: high to low') }}</option>
                        <option value="top_rated" @selected($sort==='top_rated')>{{ ___('Top rated') }}</option>
                    </select>
                </div>
            </div>

            @if($activeFilters > 0)
            <div class="chips">
                @if($price !== '')<a class="chip" href="{{ \Juwri\Core\Support\Views\PublicSiteViewHelpers::marketplaceUrlWithOverrides(['price' => null]) }}">{{ $price === 'free' ? ___('Free') : ___('Paid') }} <span aria-hidden="true">&times;</span></a>@endif
                @foreach($industry as $k)<a class="chip" href="{{ \Juwri\Core\Support\Views\PublicSiteViewHelpers::marketplaceUrlWithOverrides(['industry' => array_values(array_diff($industry, [$k]))]) }}">{{ ___(ucfirst(str_replace('_',' ',$k))) }} <span aria-hidden="true">&times;</span></a>@endforeach
                @if((int) $minRating > 0)<a class="chip" href="{{ \Juwri\Core\Support\Views\PublicSiteViewHelpers::marketplaceUrlWithOverrides(['min_rating' => null]) }}">{{ str_repeat('★', (int) $minRating) }}+ <span aria-hidden="true">&times;</span></a>@endif
                <a class="chip chip-clear" href="{{ route('marketplace.index') }}">{{ ___('Clear all') }}</a>
            </div>
            @endif

            <div class="grid">
                @forelse($results as $theme)
                    @php
                        $shot = $theme->screenshots->first();
                        $psShot = theme_showcase_shot($theme->template_slug ?: $theme->slug);
                        $sellerName = $theme->seller?->name ?: ___('Platform');
                        $isFree = (float) $theme->price <= 0;
                    @endphp
                    <a class="card" href="{{ route('marketplace.show', $theme->slug) }}">
                        @if($theme->engine === 'blade')<span class="badge-builtin">{{ ___('Built-in') }}</span>@endif
                        @php $setLabel = \App\Models\Theme::setLabel($theme->theme_set ?? 'food'); @endphp
                        @if($setLabel)<span class="badge-builtin">{{ $setLabel }}</span>@endif
                        <div class="thumb {{ $psShot ? 'thumb--phone' : '' }}" @if(!$psShot && $shot) style="background-image:url('{{ $shot->url() }}')" @endif>
                            @if($psShot)
                                <span class="iphone"><img src="{{ $psShot }}" alt="{{ $theme->name }}" loading="lazy" decoding="async" width="480" height="987"></span>
                            @elseif(!$shot){{ ___('No preview') }}@endif
                        </div>
                        <div class="body">
                            <div class="name" dir="auto">{{ $theme->name }}</div>
                            <div class="seller" dir="auto">{{ ___('by :seller', ['seller' => $sellerName]) }}</div>
                            <div class="meta">
                                <span class="price {{ $isFree ? 'free' : '' }}">
                                    {{ $isFree ? ___('Free') : price_symbol_format($theme->price) }}
                                </span>
                                @if((int) $theme->rating_count > 0)
                                    <span class="rating">
                                        <span class="stars">{{ str_repeat('★', (int) round($theme->rating_avg)) }}{{ str_repeat('☆', 5 - (int) round($theme->rating_avg)) }}</span>
                                        {{ number_format($theme->rating_avg, 1) }} ({{ $theme->rating_count }})
                                    </span>
                                @else
                                    <span class="badge-new">{{ ___('New') }}</span>
                                @endif
                            </div>
                        </div>
                    </a>
                @empty
                    <p class="empty">{{ ___('No themes match your filters.') }}</p>
                @endforelse
            </div>
        </div>
    </div>
    </form>

    <p class="devlink">{{ ___('Build and sell your own theme') }} →
        <a href="{{ route('developers.themes') }}">{{ ___('Theme developer docs') }}</a></p>
</div>
@include('apps.store._footer')
<script nonce="{{ csp_nonce() }}">
(function () {
    var rail = document.getElementById('filterRail'),
        backdrop = document.querySelector('.sheet-backdrop');
    function setOpen(open) {
        if (!rail) return;
        rail.classList.toggle('is-open', open);
        if (backdrop) backdrop.classList.toggle('is-open', open);
        document.body.classList.toggle('sheet-lock', open);
    }
    document.querySelectorAll('[data-open-filters]').forEach(function (b) { b.addEventListener('click', function () { setOpen(true); }); });
    document.querySelectorAll('[data-close]').forEach(function (b) { b.addEventListener('click', function () { setOpen(false); }); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setOpen(false); });
})();
// Desktop filters apply instantly on change; on phones they batch until "Show results".
function facetChanged(el) { if (!window.matchMedia('(max-width:820px)').matches) { el.form.submit(); } }
</script>
</body>
</html>
