{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<!DOCTYPE html>
{{-- stores P1-6: theme detail — screenshot scroller, demo CTA, version/changelog,
     seller + support, and the owner buy(wallet)/apply flow. Linen palette, RTL via
     logical CSS. Refs: Amazon app detail (updates/developer blocks) + Etsy listing
     (thumbnail-strip scroller). --}}
@php
    $rtl = function_exists('current_language') && current_language() ? current_language()->direction === 'rtl' : false;
    /* Social crawlers can't render SVG. Prefer the generated PNG raster; else a
       raster (jpg/png/webp) partner screenshot; never an SVG. */
    $ogRel = 'storage/marketplace/theme/'.$theme->id.'/og.png';
    $ogImage = is_file(public_path($ogRel)) ? asset($ogRel) : optional($screenshots->first())->url();
    if ($ogImage && \Illuminate\Support\Str::endsWith(strtok($ogImage, '?'), '.svg')) { $ogImage = null; }
@endphp
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $theme->name }} — {{ ___('Theme Store') }} — {{ $siteName }}</title>
    <meta name="description" content="{{ $theme->tagline ?: mb_substr(strip_tags((string) ($version->manifest['description'] ?? '')), 0, 160) }}">
    <meta property="og:title" content="{{ $theme->name }} — {{ $siteName }}">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ route('marketplace.show', $theme->slug) }}">
    @if($ogImage)<meta property="og:image" content="{{ $ogImage }}"><meta name="twitter:card" content="summary_large_image">@endif
    <link rel="canonical" href="{{ route('marketplace.show', $theme->slug) }}">
    @php
        $ld = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $theme->name,
            'description' => $theme->tagline ?: mb_substr(strip_tags((string) ($version->manifest['description'] ?? '')), 0, 200),
            'category' => 'Website theme',
            'url' => route('marketplace.show', $theme->slug),
            'offers' => [
                '@type' => 'Offer',
                'price' => number_format((float) $theme->price, 2, '.', ''),
                'priceCurrency' => config('settings.currency_code', 'USD'),
                'availability' => 'https://schema.org/InStock',
            ],
        ];
        if ($ogImage) { $ld['image'] = $ogImage; }
        if ((int) $theme->rating_count > 0) {
            $ld['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => number_format((float) $theme->rating_avg, 1),
                'reviewCount' => (int) $theme->rating_count,
            ];
        }
    @endphp
    <script type="application/ld+json">{!! json_ld($ld) !!}</script>
    <style nonce="{{ csp_nonce() }}">
        :root {
            --bg:#f9fafb; --panel:#ffffff; --ink:#1f1c2b; --muted:#6c6b70;
            --line:#e7e6ee; --accent:#8b3dff; --accent-ink:#6524cc; --star:#ffb400; --ok:#198754;
        }
        * { box-sizing:border-box; }
        body { margin:0; background:var(--bg); color:var(--ink);
            font:16px/1.6 'Instrument Sans',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; }
        a { color:var(--accent-ink); text-decoration:none; }
        .wrap { max-width:1060px; margin:0 auto; padding-inline:24px; padding-block:28px 80px; }
        .back { color:var(--muted); font-size:14px; }
        h1 { font-family:'Syne','Instrument Sans',sans-serif; font-size:30px; margin:14px 0 4px; letter-spacing:-.01em; }
        .subrow { display:flex; align-items:center; gap:14px; flex-wrap:wrap; color:var(--muted); margin-bottom:20px; }
        .subrow .seller { color:var(--ink); }
        .rating { color:var(--muted); font-size:14px; }
        .rating .stars { color:var(--star); letter-spacing:1px; }
        .badge-new { font-size:12px; font-weight:600; color:var(--accent); border:1px solid var(--line); border-radius:999px; padding:1px 9px; }
        .badge-builtin { font-size:12px; font-weight:600; color:#fff; background:var(--accent); border-radius:999px; padding:1px 9px; }

        .hero { display:grid; grid-template-columns:1.35fr 1fr; gap:32px; align-items:start; }
        @media (max-width:820px){ .hero{ grid-template-columns:1fr; } }

        .gallery .main { background:var(--panel) center/cover no-repeat; border:1px solid var(--line);
            border-radius:16px; aspect-ratio:16/10; display:grid; place-items:center; color:var(--muted); overflow:hidden; }
        .gallery .main img { width:100%; height:100%; object-fit:cover; display:block; }
        /* Screenshot themes: the storefront capture stands in a full iPhone frame. */
        .gallery .main--phone { aspect-ratio:auto; height:auto; background:linear-gradient(165deg,#efe7d7,#e2d6bf); padding:26px; }
        .gallery .main--phone .iphone-lg { display:block; position:relative; height:380px; aspect-ratio:480/987; margin-inline:auto;
            background:#0f0f10; border-radius:30px; padding:8px; box-shadow:0 18px 40px rgba(0,0,0,.32); }
        .gallery .main--phone .iphone-lg::before { content:""; position:absolute; top:8px; left:50%; transform:translateX(-50%);
            width:54px; height:15px; background:#0f0f10; border-radius:0 0 11px 11px; z-index:2; }
        .gallery .main--phone .iphone-lg img { width:100%; height:100%; object-fit:cover; object-position:top center; border-radius:23px; }
        .thumbs { display:flex; gap:10px; margin-top:12px; overflow-x:auto; padding-bottom:4px; }
        .thumbs button { flex:0 0 auto; width:96px; aspect-ratio:16/10; border:1px solid var(--line); border-radius:10px;
            background:var(--panel) center/cover no-repeat; cursor:pointer; padding:0; overflow:hidden; }
        .thumbs button.active { border-color:var(--accent); outline:2px solid color-mix(in srgb, var(--accent) 25%, transparent); }
        .thumbs img { width:100%; height:100%; object-fit:cover; display:block; }

        .buy { background:var(--panel); border:1px solid var(--line); border-radius:16px; padding:22px; position:sticky; top:82px; }
        .metalist { list-style:none; margin:16px 0 0; padding:14px 0 0; border-top:1px solid var(--line); font-size:14px; }
        .metalist li { display:flex; justify-content:space-between; gap:12px; padding-block:4px; }
        .metalist .k { color:var(--muted); }
        .about p { margin:0 0 14px; }
        .about p:last-child { margin-bottom:0; }
        ul.feats { list-style:none; margin:0 0 14px; padding:0; }
        ul.feats li { display:flex; gap:11px; align-items:baseline; padding-block:6px; }
        ul.feats li::before { content:'✓'; color:var(--ok); font-weight:800; flex:0 0 auto; }
        ul.feats strong { font-weight:700; }
        .price { color:var(--accent-ink); font-weight:800; font-size:26px; }
        .price.free { color:var(--ok); }
        .muted { color:var(--muted); }
        .btn { display:inline-block; border:0; cursor:pointer; background:var(--accent); color:#fff; font-weight:700;
            padding:12px 22px; border-radius:999px; font:inherit; text-align:center; }
        .btn.ghost { background:transparent; border:1px solid var(--line); color:var(--ink); }
        .btn[disabled] { opacity:.5; cursor:default; }
        .btn.block { display:block; width:100%; margin-top:12px; }
        select { padding:10px 14px; border-radius:10px; border:1px solid var(--line); background:var(--bg); color:var(--ink); font:inherit; width:100%; }
        .msg { font-size:14px; margin-top:10px; }

        section.block { background:var(--panel); border:1px solid var(--line); border-radius:16px; padding:22px 24px; margin-top:22px; }
        section.block h2 { font-family:'Syne','Instrument Sans',sans-serif; font-size:20px; margin:0 0 12px; }
        .vlist { list-style:none; margin:0; padding:0; }
        .vlist li { display:flex; gap:12px; align-items:baseline; padding-block:8px; border-top:1px solid var(--line); }
        .vlist li:first-child { border-top:0; }
        .vlist code { background:var(--bg); border-radius:6px; padding:1px 8px; color:var(--accent-ink); font-weight:700; }
        .vlist .when { color:var(--muted); font-size:13px; margin-inline-start:auto; }
        .support a { color:var(--accent-ink); font-weight:600; }
    </style>
    {{-- Saudi Riyal (U+20C1) / Dirham (U+20C3) glyph font, so store prices in SAR/AED render the sign instead of a tofu box. --}}
    @include('partials.riyal-font')
    <script src="{{ asset('assets/js/csp-actions.js') }}?v={{ filemtime(public_path('assets/js/csp-actions.js')) }}"></script>
</head>
<body>
@include('apps.store._nav', ['asNavActive' => 'themes'])
<div class="wrap">
    <a class="back" href="{{ route('marketplace.index') }}">← {{ ___('All themes') }}</a>

    @php
        $sellerName = $theme->seller?->name ?: ___('Platform');
        $isFree = (float) $theme->price <= 0;
        $main = $screenshots->first();
    @endphp

    <h1 dir="auto">{{ $theme->name }}</h1>
    <div class="subrow">
        <span class="seller" dir="auto">{{ ___('by :seller', ['seller' => $sellerName]) }}</span>
        <span>·</span>
        @if((int) $theme->rating_count > 0)
            <span class="rating"><span class="stars">{{ str_repeat('★', (int) round($theme->rating_avg)) }}{{ str_repeat('☆', 5 - (int) round($theme->rating_avg)) }}</span>
                {{ number_format($theme->rating_avg, 1) }} ({{ $theme->rating_count }})</span>
        @else
            <span class="badge-new">{{ ___('New') }}</span>
        @endif
        @if($theme->category)<span>·</span><span>{{ ___(ucfirst(str_replace('_',' ',$theme->category))) }}</span>@endif
        @if($isBlade)<span>·</span><span class="badge-builtin">{{ ___('Built-in') }}</span>@endif
    </div>

    <div class="hero">
        {{-- Gallery --}}
        <div class="gallery">
            <div class="main {{ ($heroShot ?? null) ? 'main--phone' : '' }}" id="galleryMain">
                @if($heroShot ?? null)<span class="iphone-lg"><img src="{{ $heroShot }}" alt="{{ $theme->name }}" id="galleryImg"></span>
                @elseif($main)<img src="{{ $main->url() }}" alt="{{ $theme->name }}" id="galleryImg">
                @elseif($previewUrl)<img src="{{ $previewUrl }}" alt="{{ $theme->name }}" id="galleryImg">
                @else{{ ___('No preview') }}@endif
            </div>
            @if($screenshots->count() > 1)
                <div class="thumbs" role="list">
                    @foreach($screenshots as $i => $shot)
                        <button type="button" class="{{ $i === 0 ? 'active' : '' }}" data-src="{{ $shot->url() }}"
                                data-act="pickShot" data-this aria-label="{{ ___('Screenshot') }} {{ $i + 1 }}">
                            <img src="{{ $shot->url() }}" alt="">
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Buy / apply panel --}}
        <aside class="buy">
            <div class="price {{ $isFree ? 'free' : '' }}">{{ $isFree ? ___('Free') : price_symbol_format($theme->price) }}</div>
            @if($theme->tagline)<p class="muted" style="margin:6px 0 0;" dir="auto">{{ $theme->tagline }}</p>@endif

            {{-- The LIVE DEMO: a real storefront owned by the showcase account
                 that renders THIS theme (every listing has one — the Liquid
                 themes' demos are the showcase clones), so the button always
                 leads to a real shop rather than a synthetic render. The
                 preview (the theme rendered against the demo dataset) stays
                 as a second link for the Liquid themes. --}}
            <a class="btn ghost block" href="{{ $demoUrl ?: route('marketplace.preview', $theme->slug) }}" target="_blank" rel="noopener">
                {{ $demoUrl ? ___('View live demo') : ___('Live preview (demo store)') }}</a>
            @if($demoUrl)
                <a class="btn ghost block" href="{{ route('marketplace.preview', $theme->slug) }}" target="_blank" rel="noopener">
                    {{ ___('Live preview (demo store)') }}</a>
            @endif

            @auth
                <div id="actions" data-entitled="{{ $entitled ? '1' : '0' }}"
                     data-buy="{{ route('marketplace.buy', $theme->slug) }}"
                     data-apply="{{ route('marketplace.apply', $theme->slug) }}">
                    @if(!$entitled && !$isFree)
                        <button class="btn block" id="buyBtn">{{ ___('Buy license') }} — {{ price_symbol_format($theme->price) }}</button>
                        <p class="muted msg">{{ ___('Charged to your wallet balance. One purchase covers all your restaurants.') }}</p>
                    @endif
                    <div id="applyRow" @if(!$entitled && !$isFree) hidden @endif>
                        @if($stores->isEmpty())
                            <p class="muted msg">{{ ___('Create a restaurant first, then apply the theme.') }}</p>
                        @else
                            <select id="applyTarget" style="margin-top:12px;">
                                @foreach($stores as $r)<option value="{{ $r->id }}" dir="auto">{{ $r->title }}</option>@endforeach
                            </select>
                            <button class="btn block" id="applyBtn">{{ ___('Apply to this restaurant') }}</button>
                        @endif
                    </div>
                    <p class="msg" id="msg"></p>
                </div>
            @else
                <a class="btn block" href="{{ route('login') }}">{{ ___('Sign in to buy or apply this theme.') }}</a>
            @endauth

            <ul class="metalist">
                <li><span class="k">{{ ___('Version') }}</span><span>{{ $version->semver }}</span></li>
                <li><span class="k">{{ ___('Launched') }}</span><span>{{ $theme->created_at?->translatedFormat('M Y') }}</span></li>
                @if($theme->category)<li><span class="k">{{ ___('Industry') }}</span><span>{{ ___(ucfirst(str_replace('_',' ',$theme->category))) }}</span></li>@endif
                <li><span class="k">{{ ___('Built by') }}</span><span dir="auto">{{ $sellerName }}</span></li>
            </ul>
        </aside>
    </div>

    {{-- About: hook paragraphs + "Feature — benefit" bullet blocks --}}
    <section class="block about">
        <h2>{{ ___('About this theme') }}</h2>
        @php $themeBlocks = preg_split('/\n\s*\n/', trim((string) ($aboutText ?? $version->manifest['description'] ?? $theme->tagline ?? ''))); @endphp
        @foreach($themeBlocks as $block)
            @if(preg_match('/^\s*•/u', $block))
                <ul class="feats">
                    @foreach(preg_split('/\n+/', trim($block)) as $line)
                        @php
                            $line = preg_replace('/^•\s*/u', '', trim($line));
                            $parts = preg_split('/\s+—\s+/u', $line, 2);
                        @endphp
                        <li dir="auto">@if(count($parts) === 2)<span><strong>{{ $parts[0] }}</strong> — {{ $parts[1] }}</span>@else<span>{{ $line }}</span>@endif</li>
                    @endforeach
                </ul>
            @else
                <p dir="auto">{{ trim($block) }}</p>
            @endif
        @endforeach
        @if($theme->localizedNotes())<p class="muted" style="margin:10px 0 0;" dir="auto">{{ $theme->localizedNotes() }}</p>@endif
    </section>

    {{-- Version & changelog --}}
    <section class="block">
        <h2>{{ ___('Version & changelog') }}</h2>
        <ul class="vlist">
            @foreach($versions as $v)
                <li>
                    <code>{{ $v->semver }}</code>
                    <span class="muted" dir="auto">{{ $v->review_notes ?: ___('Reviewed & quality-gated') }}</span>
                    <span class="when">{{ $v->created_at?->diffForHumans() }}</span>
                </li>
            @endforeach
        </ul>
    </section>

    {{-- Seller & support --}}
    <section class="block support">
        <h2>{{ ___('Support') }}</h2>
        <p class="muted" style="margin:0 0 6px;" dir="auto">{{ ___('by :seller', ['seller' => $sellerName]) }}</p>
        @if($theme->support_url)
            <p style="margin:0;"><a href="{{ $theme->support_url }}" target="_blank" rel="noopener nofollow">{{ ___('Contact support') }} →</a></p>
        @else
            <p class="muted" style="margin:0;">{{ ___('Support is provided by the theme author.') }}</p>
        @endif
    </section>

    @include('marketplace._review_list', [
        'avg' => (float) $theme->rating_avg,
        'count' => (int) $theme->rating_count,
        'distribution' => $distribution,
        'reviews' => $publishedReviews,
    ])

    @include('marketplace._review_form', [
        'action' => route('marketplace.review', $theme->slug),
        'eligible' => $eligibleToReview,
        'myReview' => $myReview,
        'ineligibleHint' => ___('Only buyers can review this theme.'),
    ])
</div>
@include('apps.store._footer')

<script nonce="{{ csp_nonce() }}">
    function pickShot(btn){
        var img = document.getElementById('galleryImg');
        if (img) img.src = btn.dataset.src;
        document.querySelectorAll('.thumbs button').forEach(function(b){ b.classList.remove('active'); });
        btn.classList.add('active');
    }
</script>
@auth
<script nonce="{{ csp_nonce() }}">
(function () {
    const box = document.getElementById('actions');
    const msg = document.getElementById('msg');
    const headers = { 'X-CSRF-TOKEN': '{{ csrf_token() }}', Accept: 'application/json' };

    document.getElementById('buyBtn')?.addEventListener('click', async function () {
        this.disabled = true;
        const r = await fetch(box.dataset.buy, { method: 'POST', headers });
        const d = await r.json();
        if (d.ok) {
            msg.textContent = '{{ ___('License purchased — apply it below.') }}';
            document.getElementById('applyRow').hidden = false;
            this.remove();
        } else {
            msg.textContent = d.message || '{{ ___('Purchase failed — check your wallet balance.') }}';
            this.disabled = false;
        }
    });

    document.getElementById('applyBtn')?.addEventListener('click', async function () {
        this.disabled = true;
        const r = await fetch(box.dataset.apply, {
            method: 'POST',
            headers: { ...headers, 'Content-Type': 'application/json' },
            body: JSON.stringify({ store_id: document.getElementById('applyTarget').value }),
        });
        const d = await r.json();
        msg.textContent = d.ok ? '{{ ___('Theme applied — open your menu to see it live.') }}' : '{{ ___('Could not apply the theme.') }}';
        this.disabled = false;
    });
})();
</script>
@endauth
</body>
</html>
