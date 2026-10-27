{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<!DOCTYPE html>
{{-- stores P2-1: public App Store browse (approved apps only). Self-contained,
     linen palette, RTL via logical CSS. Faceted rail (Pricing + Category) with
     live counts + zero-hit disabled, search, sort, icon-led card grid (Shopify
     App Store card anatomy: icon, name, subtitle, rating, pricing label). --}}
@php $rtl = function_exists('current_language') && current_language() ? current_language()->direction === 'rtl' : false; @endphp
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ ___('App Store') }} — {{ $siteName }}</title>
    <meta name="description" content="{{ ___('Browse apps that power your restaurant — ordering, marketing, delivery, analytics and more, built on :site.', ['site' => $siteName]) }}">
    <meta property="og:title" content="{{ ___('App Store') }} — {{ $siteName }}">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ route('apps.store.index') }}">
    <link rel="canonical" href="{{ route('apps.store.index') }}">
    <style nonce="{{ csp_nonce() }}">
        :root { --bg:#f9fafb; --panel:#ffffff; --ink:#1f1c2b; --muted:#6c6b70;
            --line:#e7e6ee; --accent:#8b3dff; --accent-ink:#6524cc; --star:#ffb400; --ok:#198754; }
        * { box-sizing:border-box; }
        body { margin:0; background:var(--bg); color:var(--ink); font:16px/1.6 'Instrument Sans',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; }
        a { color:inherit; text-decoration:none; }
        .wrap { max-width:1180px; margin:0 auto; padding-inline:24px; }
        header.top { padding-block:44px 8px; display:flex; align-items:flex-end; justify-content:space-between; gap:20px; flex-wrap:wrap; }
        h1 { font-family:'Syne','Instrument Sans',sans-serif; font-size:34px; margin:0 0 6px; letter-spacing:-.01em; }
        header.top p { color:var(--muted); margin:0; max-width:60ch; }
        .build a { display:inline-block; border:1px solid var(--line); border-radius:999px; padding:9px 18px; font-size:14px; font-weight:600; color:var(--accent-ink); background:var(--panel); }

        .layout { display:grid; grid-template-columns:250px 1fr; gap:32px; padding-block:28px 40px; align-items:start; }
        @media (max-width:820px){ .layout{ grid-template-columns:1fr; } }

        /* ── Filters: a sticky sidebar on desktop, a bottom sheet on phones ── */
        aside.rail { position:sticky; top:82px; background:var(--panel); border:1px solid var(--line); border-radius:16px; padding:20px; }
        .rhead { display:flex; align-items:center; justify-content:space-between; margin-bottom:8px; }
        .rhead h2 { font-size:13px; text-transform:uppercase; letter-spacing:.08em; color:var(--muted); margin:0; }
        .rail .clear { font-size:13px; color:var(--accent); }
        .fcount { background:var(--accent); color:#fff; font-size:12px; font-weight:700; border-radius:999px;
            min-width:19px; height:19px; display:inline-grid; place-items:center; padding:0 5px; }
        .sheet-head, .sheet-foot, .sheet-backdrop, .filterbtn, .chips { display:none; }
        @media (max-width:820px){
            .filterbtn { display:inline-flex; align-items:center; gap:8px; cursor:pointer; border:1px solid var(--line);
                background:var(--panel); color:var(--ink); font:inherit; font-weight:600; font-size:14px;
                padding:10px 16px; border-radius:999px; }
            .filterbtn svg { width:17px; height:17px; }
            .chips { display:flex; flex-wrap:wrap; gap:8px; margin:0 0 18px; }
            .chip { display:inline-flex; align-items:center; gap:6px; font-size:13px; font-weight:600; color:var(--ink);
                background:var(--panel); border:1px solid var(--line); border-radius:999px; padding:5px 12px; }
            .chip span { color:var(--muted); font-size:13px; }
            .chip-clear { color:var(--accent); border-color:transparent; background:transparent; }
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
            .grid { grid-template-columns:1fr; }
        }
        @media (prefers-reduced-motion:reduce){ aside.rail, .sheet-backdrop { transition:none; } }
        .fgroup { border-top:1px solid var(--line); padding-block:14px; }
        .fgroup > .flabel { font-weight:600; font-size:14px; margin-bottom:8px; }
        .frow { display:flex; align-items:center; gap:9px; padding-block:4px; font-size:14.5px; cursor:pointer; }
        .frow input { accent-color:var(--accent); }
        .frow.disabled { color:var(--muted); cursor:not-allowed; opacity:.55; }
        .frow .cnt { margin-inline-start:auto; color:var(--muted); font-size:12.5px; background:var(--bg); border-radius:999px; padding:1px 8px; }

        .main .bar { display:flex; align-items:center; gap:16px; flex-wrap:wrap; margin-bottom:20px; }
        .count { font-weight:600; }
        .search input { width:100%; padding:11px 16px; border-radius:999px; border:1px solid var(--line); background:var(--panel); color:var(--ink); font:inherit; }
        .sort select { padding:10px 14px; border-radius:10px; border:1px solid var(--line); background:var(--panel); color:var(--ink); font:inherit; }

        .grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(310px,1fr)); gap:18px; }
        .card { background:var(--panel); border:1px solid var(--line); border-radius:16px; padding:18px; display:flex; gap:14px; align-items:flex-start; transition:border-color .15s, box-shadow .15s, transform .15s; }
        .card:hover { border-color:var(--accent); box-shadow:0 8px 26px rgba(23,15,46,.10); transform:translateY(-2px); }
        .card .icon { flex:0 0 auto; width:60px; height:60px; border-radius:15px; overflow:hidden; background:var(--chip,#efe7d7); display:grid; place-items:center; font-weight:800; font-size:20px; color:var(--accent-ink); }
        .card .icon img { width:100%; height:100%; display:block; }
        .card .body { min-width:0; }
        .card .name { font-weight:700; font-size:16px; }
        .card .dev { color:var(--muted); font-size:13px; margin-top:1px; }
        .card .tagline { font-size:13.5px; color:var(--ink); margin-top:6px; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; min-height:2.5em; }
        .card .meta { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-top:9px; }
        .pill { font-size:12px; font-weight:600; color:var(--accent-ink); background:var(--bg); border-radius:999px; padding:2px 10px; }
        .pill.free { color:var(--ok); }
        .rating { font-size:13px; color:var(--muted); display:inline-flex; align-items:center; gap:4px; }
        .rating .stars { color:var(--star); letter-spacing:1px; }
        .badge-new { font-size:11.5px; font-weight:600; color:var(--accent); border:1px solid var(--line); border-radius:999px; padding:1px 8px; }
        .empty { color:var(--muted); text-align:center; padding:70px 0; grid-column:1/-1; }
    </style>
    <script src="{{ asset('assets/js/csp-actions.js') }}?v={{ filemtime(public_path('assets/js/csp-actions.js')) }}"></script>
</head>
<body>
@include('apps.store._nav', ['asNavActive' => 'apps'])
@php $hasFilters = $q !== '' || $pricing !== '' || !empty($category); @endphp
<div class="wrap">
    <header class="top">
        <div>
            <h1>{{ ___('App Store') }}</h1>
            <p>{{ ___('Apps that power your :noun', ['noun' => store_noun(null, 'poss')]) }}</p>
        </div>
        <div class="build">
            @auth<a href="{{ route('apps.page') }}" style="margin-inline-end:14px;">{{ ___('My apps') }}</a>@endauth
            <a href="{{ route('developers') }}">{{ ___('Build an app') }} →</a>
        </div>
    </header>

    <form method="get" action="{{ route('apps.store.index') }}" id="browse">
    <div class="layout">
        {{-- Filter rail. Desktop: a sticky sidebar (facets submit on change). Phones:
             a bottom sheet opened from the "Filters" button; facets batch until
             "Show results" so the page doesn't reload on every tap. --}}
        @php
            $activeFilters = ($pricing !== '' ? 1 : 0) + count($category) + ((int) $minRating > 0 ? 1 : 0);
            // URL with query params overridden/removed — powers the removable chips.
            $qs = function (array $overrides) {
                $params = array_filter(array_merge(request()->query(), $overrides),
                    fn ($v) => $v !== null && $v !== '' && $v !== []);
                $query = http_build_query($params);
                return route('apps.store.index').($query ? '?'.$query : '');
            };
        @endphp
        <div class="sheet-backdrop" data-close aria-hidden="true"></div>
        <aside class="rail" id="filterRail" aria-label="{{ ___('Filters') }}">
            <div class="sheet-head">
                <span>{{ ___('Filters') }}</span>
                <button type="button" class="sheet-x" data-close aria-label="{{ ___('Close') }}">&times;</button>
            </div>
            <div class="rhead">
                <h2>{{ ___('Filters') }}</h2>
                @if($hasFilters)<a class="clear" href="{{ route('apps.store.index') }}">{{ ___('Clear all') }}</a>@endif
            </div>

            <div class="fgroup">
                <div class="flabel">{{ ___('Pricing') }}</div>
                <label class="frow">
                    <input type="radio" name="pricing" value="" @checked($pricing === '') data-change="facetChanged" data-this>
                    <span>{{ ___('Featured') }}</span><span class="cnt">{{ $freeCount + $paidCount }}</span>
                </label>
                <label class="frow {{ $freeCount === 0 ? 'disabled' : '' }}">
                    <input type="radio" name="pricing" value="free" @checked($pricing === 'free') @disabled($freeCount === 0) data-change="facetChanged" data-this>
                    <span>{{ ___('Free') }}</span><span class="cnt">{{ $freeCount }}</span>
                </label>
                <label class="frow {{ $paidCount === 0 ? 'disabled' : '' }}">
                    <input type="radio" name="pricing" value="paid" @checked($pricing === 'paid') @disabled($paidCount === 0) data-change="facetChanged" data-this>
                    <span>{{ ___('Paid') }}</span><span class="cnt">{{ $paidCount }}</span>
                </label>
            </div>

            <div class="fgroup">
                <div class="flabel">{{ ___('Category') }}</div>
                @foreach($categories as $key)
                    @php $c = $categoryCounts[$key]; $checked = in_array($key, $category, true); @endphp
                    <label class="frow {{ ($c === 0 && !$checked) ? 'disabled' : '' }}">
                        <input type="checkbox" name="category[]" value="{{ $key }}" @checked($checked) @disabled($c === 0 && !$checked) data-change="facetChanged" data-this>
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
                <a class="clear" href="{{ route('apps.store.index') }}">{{ ___('Clear all') }}</a>
                <button type="submit" class="applybtn">{{ ___('Show results') }}</button>
            </div>
        </aside>

        <div class="main">
            <div class="bar">
                <button type="button" class="filterbtn" data-open-filters>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon></svg>
                    {{ ___('Filters') }}@if($activeFilters > 0)<span class="fcount">{{ $activeFilters }}</span>@endif
                </button>
                <span class="count">{{ ___(':count apps', ['count' => $total]) }}</span>
                <div class="search" style="flex:1;min-width:200px;">
                    <input type="search" name="q" value="{{ $q }}" placeholder="{{ ___('Search apps') }}"
                           aria-label="{{ ___('Search apps') }}" data-search="submitBrowse">
                </div>
                <div class="sort">
                    <select name="sort" data-change="submitBrowse" aria-label="{{ ___('Sort by') }}">
                        <option value="featured" @selected($sort==='featured')>{{ ___('Featured') }}</option>
                        <option value="name" @selected($sort==='name')>{{ ___('Name (A–Z)') }}</option>
                        <option value="top_rated" @selected($sort==='top_rated')>{{ ___('Top rated') }}</option>
                    </select>
                </div>
            </div>

            @if($activeFilters > 0)
            <div class="chips">
                @if($pricing !== '')<a class="chip" href="{{ $qs(['pricing' => null]) }}">{{ $pricing === 'free' ? ___('Free') : ___('Paid') }} <span aria-hidden="true">&times;</span></a>@endif
                @foreach($category as $k)<a class="chip" href="{{ $qs(['category' => array_values(array_diff($category, [$k]))]) }}">{{ ___(ucfirst(str_replace('_',' ',$k))) }} <span aria-hidden="true">&times;</span></a>@endforeach
                @if((int) $minRating > 0)<a class="chip" href="{{ $qs(['min_rating' => null]) }}">{{ str_repeat('★', (int) $minRating) }}+ <span aria-hidden="true">&times;</span></a>@endif
                <a class="chip chip-clear" href="{{ route('apps.store.index') }}">{{ ___('Clear all') }}</a>
            </div>
            @endif

            <div class="grid">
                @forelse($results as $app)
                    @php $devName = $app->developer?->name ?: ___('Platform'); @endphp
                    <a class="card" href="{{ url('apps/store/'.$app->slug) }}">
                        <span class="icon">
                            @if($app->icon)<img src="{{ $app->icon->url() }}" alt="" loading="lazy">
                            @else{{ mb_substr($app->name, 0, 1) }}@endif
                        </span>
                        <span class="body">
                            <span class="name">{{ $app->name }}</span>
                            <span class="dev" style="display:block;">{{ ___('by :developer', ['developer' => $devName]) }}</span>
                            <span class="tagline" style="display:block;">{{ $app->tagline }}</span>
                            <span class="meta">
                                @if((int) $app->rating_count > 0)
                                    <span class="rating"><span class="stars">★</span>{{ number_format($app->rating_avg, 1) }} ({{ $app->rating_count }})</span>
                                @else
                                    <span class="badge-new">{{ ___('New') }}</span>
                                @endif
                                <span class="pill {{ $app->isFreeBilling() ? 'free' : '' }}">{{ $app->pricingSummary() }}</span>
                            </span>
                        </span>
                    </a>
                @empty
                    <p class="empty">{{ ___('No apps match your filters.') }}</p>
                @endforelse
            </div>
        </div>
    </div>
    </form>
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
