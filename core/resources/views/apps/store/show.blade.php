{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<!DOCTYPE html>
{{-- stores P2-2: app detail — screenshot gallery, pricing table (per billing_type),
     human-readable scopes, developer/support/docs, install CTA. Linen + RTL. Ref:
     Shopify App Store detail (icon header, gallery, highlights bullets, pricing,
     reviews with distribution, support block, launch-date trust signal). --}}
@php
    $rtl = function_exists('current_language') && current_language() ? current_language()->direction === 'rtl' : false;
    /* Social crawlers can't render SVG. Prefer the generated PNG raster; else a
       raster (jpg/png/webp) partner screenshot; never an SVG. */
    $ogRel = 'storage/marketplace/app/'.$app->id.'/og.png';
    $ogImage = is_file(public_path($ogRel)) ? asset($ogRel) : optional($screenshots->first())->url();
    if ($ogImage && \Illuminate\Support\Str::endsWith(strtok($ogImage, '?'), '.svg')) { $ogImage = null; }
@endphp
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $app->name }} — {{ ___('App Store') }} — {{ $siteName }}</title>
    <meta name="description" content="{{ $app->tagline ?: mb_substr(strip_tags((string) $app->description), 0, 160) }}">
    <meta property="og:title" content="{{ $app->name }} — {{ $siteName }}">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ route('apps.store.show', $app->slug) }}">
    @if($ogImage)<meta property="og:image" content="{{ $ogImage }}"><meta name="twitter:card" content="summary_large_image">@endif
    <link rel="canonical" href="{{ route('apps.store.show', $app->slug) }}">
    @php
        $ld = [
            '@context' => 'https://schema.org',
            '@type' => 'SoftwareApplication',
            'name' => $app->name,
            'description' => $app->tagline ?: mb_substr(strip_tags((string) $app->description), 0, 200),
            'applicationCategory' => 'BusinessApplication',
            'operatingSystem' => 'Web',
            'url' => route('apps.store.show', $app->slug),
            'offers' => [
                '@type' => 'Offer',
                'price' => number_format((float) $app->price, 2, '.', ''),
                'priceCurrency' => config('settings.currency_code', 'USD'),
            ],
        ];
        if ($ogImage) { $ld['image'] = $ogImage; }
        if ((int) $app->rating_count > 0) {
            $ld['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => number_format((float) $app->rating_avg, 1),
                'reviewCount' => (int) $app->rating_count,
            ];
        }
    @endphp
    <script type="application/ld+json">{!! json_ld($ld) !!}</script>
    <style nonce="{{ csp_nonce() }}">
        :root { --bg:#f9fafb; --panel:#ffffff; --ink:#1f1c2b; --muted:#6c6b70;
            --line:#e7e6ee; --accent:#8b3dff; --accent-ink:#6524cc; --star:#ffb400; --ok:#198754; }
        * { box-sizing:border-box; }
        body { margin:0; background:var(--bg); color:var(--ink); font:16px/1.6 'Instrument Sans',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; }
        a { color:var(--accent-ink); text-decoration:none; }
        .wrap { max-width:1060px; margin:0 auto; padding-inline:24px; padding-block:28px 40px; }
        .back { color:var(--muted); font-size:14px; }
        .headrow { display:flex; align-items:center; gap:18px; margin:18px 0 4px; }
        .appicon { flex:0 0 auto; width:76px; height:76px; border-radius:19px; overflow:hidden; background:var(--chip,#efe7d7); display:grid; place-items:center; font-weight:800; font-size:26px; color:var(--accent-ink); box-shadow:0 4px 14px rgba(23,15,46,.12); }
        .appicon img { width:100%; height:100%; display:block; }
        h1 { font-family:'Syne','Instrument Sans',sans-serif; font-size:30px; margin:0 0 2px; letter-spacing:-.01em; }
        .subrow { display:flex; align-items:center; gap:12px; flex-wrap:wrap; color:var(--muted); }
        .rating .stars { color:var(--star); letter-spacing:1px; }
        .badge-new { font-size:12px; font-weight:600; color:var(--accent); border:1px solid var(--line); border-radius:999px; padding:1px 9px; }

        .hero { display:grid; grid-template-columns:1.35fr 1fr; gap:32px; align-items:start; margin-top:20px; }
        @media (max-width:820px){ .hero{ grid-template-columns:1fr; } }
        .gallery .main { background:var(--panel) center/cover no-repeat; border:1px solid var(--line); border-radius:16px; aspect-ratio:16/10; display:grid; place-items:center; color:var(--muted); overflow:hidden; }
        .gallery .main img { width:100%; height:100%; object-fit:cover; display:block; }
        .thumbs { display:flex; gap:10px; margin-top:12px; overflow-x:auto; padding-bottom:4px; }
        .thumbs button { flex:0 0 auto; width:96px; aspect-ratio:16/10; border:1px solid var(--line); border-radius:10px; background:var(--panel) center/cover no-repeat; cursor:pointer; padding:0; overflow:hidden; }
        .thumbs button.active { border-color:var(--accent); outline:2px solid color-mix(in srgb, var(--accent) 25%, transparent); }
        .thumbs img { width:100%; height:100%; object-fit:cover; display:block; }

        .install { background:var(--panel); border:1px solid var(--line); border-radius:16px; padding:22px; position:sticky; top:82px; }
        .install .pill { display:inline-block; font-weight:700; color:var(--accent-ink); }
        .install .pill.free { color:var(--ok); }
        .btn { display:block; width:100%; text-align:center; border:0; cursor:pointer; background:var(--accent); color:#fff; font-weight:700; padding:12px 22px; border-radius:999px; font:inherit; margin-top:14px; }
        .btn.ghost { background:transparent; border:1px solid var(--line); color:var(--ink); }
        .btn[disabled]{ opacity:.5; cursor:default; }
        .installed { margin-top:14px; padding:12px; border:1px solid var(--line); border-radius:12px; color:var(--ok); font-weight:600; text-align:center; }
        .msg { font-size:14px; margin-top:10px; }
        .metalist { list-style:none; margin:16px 0 0; padding:14px 0 0; border-top:1px solid var(--line); font-size:14px; }
        .metalist li { display:flex; justify-content:space-between; gap:12px; padding-block:4px; }
        .metalist .k { color:var(--muted); }

        section.block { background:var(--panel); border:1px solid var(--line); border-radius:16px; padding:22px 24px; margin-top:22px; }
        section.block h2 { font-family:'Syne','Instrument Sans',sans-serif; font-size:20px; margin:0 0 14px; }
        .about p { margin:0 0 14px; }
        .about p:last-child { margin-bottom:0; }
        ul.feats { list-style:none; margin:0 0 14px; padding:0; }
        ul.feats li { display:flex; gap:11px; align-items:baseline; padding-block:6px; }
        ul.feats li::before { content:'✓'; color:var(--ok); font-weight:800; flex:0 0 auto; }
        ul.feats strong { font-weight:700; }
        table.pricing { width:100%; border-collapse:collapse; }
        table.pricing th, table.pricing td { text-align:start; padding:10px 12px; border-top:1px solid var(--line); }
        table.pricing th { color:var(--muted); font-weight:600; font-size:13px; text-transform:uppercase; letter-spacing:.05em; }
        table.pricing .amt { font-weight:800; color:var(--accent-ink); }
        ul.scopes { list-style:none; margin:0; padding:0; }
        ul.scopes li { display:flex; gap:10px; align-items:baseline; padding-block:7px; border-top:1px solid var(--line); }
        ul.scopes li:first-child { border-top:0; }
        ul.scopes .key { color:var(--muted); font-size:12.5px; background:var(--bg); border-radius:6px; padding:1px 7px; }
        .devgrid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:16px; }
        .devgrid a { color:var(--accent-ink); font-weight:600; }
    </style>
    <script src="{{ asset('assets/js/csp-actions.js') }}?v={{ filemtime(public_path('assets/js/csp-actions.js')) }}"></script>
</head>
<body>
@include('apps.store._nav', ['asNavActive' => 'apps'])
<div class="wrap">
    <a class="back" href="{{ route('apps.store.index') }}">← {{ ___('All apps') }}</a>

    @php $devName = $app->developer?->name ?: ___('Platform'); $main = $screenshots->first(); @endphp

    <div class="headrow">
        <span class="appicon">
            @if($app->icon)<img src="{{ $app->icon->url() }}" alt="">
            @else{{ mb_substr($app->name, 0, 1) }}@endif
        </span>
        <div>
            <h1>{{ $app->name }}</h1>
            <div class="subrow">
                <span>{{ ___('by :developer', ['developer' => $devName]) }}</span><span>·</span>
                @if((int) $app->rating_count > 0)
                    <span class="rating"><span class="stars">{{ str_repeat('★', (int) round($app->rating_avg)) }}{{ str_repeat('☆', 5 - (int) round($app->rating_avg)) }}</span>
                        {{ number_format($app->rating_avg, 1) }} ({{ $app->rating_count }})</span>
                @else
                    <span class="badge-new">{{ ___('New') }}</span>
                @endif
                @if($app->category)<span>·</span><span>{{ ___(ucfirst(str_replace('_',' ',$app->category))) }}</span>@endif
            </div>
        </div>
    </div>

    <div class="hero">
        <div class="gallery">
            <div class="main" id="galleryMain">
                @if($main)<img src="{{ $main->url() }}" alt="{{ $app->name }}" id="galleryImg">
                @else{{ ___('No preview') }}@endif
            </div>
            @if($screenshots->count() > 1)
                <div class="thumbs">
                    @foreach($screenshots as $i => $shot)
                        <button type="button" class="{{ $i === 0 ? 'active' : '' }}" data-src="{{ $shot->url() }}" data-act="pickShot" data-this>
                            <img src="{{ $shot->url() }}" alt=""></button>
                    @endforeach
                </div>
            @endif
        </div>

        <aside class="install">
            <div class="pill {{ $app->isFreeBilling() ? 'free' : '' }}">{{ $app->pricingSummary() }}</div>
            @if($app->tagline)<p style="margin:6px 0 0;color:var(--muted);">{{ $app->tagline }}</p>@endif

            @auth
                @if($installed)
                    <div class="installed">✓ {{ ___('Installed') }}</div>
                    <a class="btn ghost" href="{{ route('apps.page') }}">{{ ___('Manage in dashboard') }}</a>
                @else
                    <button class="btn" id="installBtn" data-install="{{ route('apps.install', $app->id) }}">{{ ___('Install app') }}</button>
                    <div id="postInstall" hidden>
                        <div class="installed">✓ {{ ___('Installed') }}</div>
                        <a class="btn ghost" href="{{ route('apps.page') }}">{{ ___('Manage in dashboard') }}</a>
                    </div>
                    <p class="msg" id="msg"></p>
                @endif
            @else
                <a class="btn" href="{{ route('login') }}">{{ ___('Sign in to install') }}</a>
            @endauth

            <ul class="metalist">
                <li><span class="k">{{ ___('Launched') }}</span><span>{{ $app->created_at?->translatedFormat('M Y') }}</span></li>
                @if($app->category)<li><span class="k">{{ ___('Category') }}</span><span>{{ ___(ucfirst(str_replace('_',' ',$app->category))) }}</span></li>@endif
                <li><span class="k">{{ ___('Built by') }}</span><span>{{ $devName }}</span></li>
            </ul>
        </aside>
    </div>

    {{-- About: hook paragraphs + "Feature — benefit" bullet blocks --}}
    <section class="block about">
        <h2>{{ ___('About this app') }}</h2>
        @php $blocks = preg_split('/\n\s*\n/', trim((string) ($app->description ?: $app->tagline))); @endphp
        @foreach($blocks as $block)
            @if(preg_match('/^\s*•/u', $block))
                <ul class="feats">
                    @foreach(preg_split('/\n+/', trim($block)) as $line)
                        @php
                            $line = preg_replace('/^•\s*/u', '', trim($line));
                            $parts = preg_split('/\s+—\s+/u', $line, 2);
                        @endphp
                        <li>@if(count($parts) === 2)<span><strong>{{ $parts[0] }}</strong> — {{ $parts[1] }}</span>@else<span>{{ $line }}</span>@endif</li>
                    @endforeach
                </ul>
            @else
                <p>{{ trim($block) }}</p>
            @endif
        @endforeach
    </section>

    {{-- Pricing table (per billing_type) --}}
    <section class="block">
        <h2>{{ ___('Pricing') }}</h2>
        <table class="pricing" data-billing="{{ $app->billing_type }}">
            @if($app->billing_type === 'free')
                <tr><th>{{ ___('Plan') }}</th><td class="amt">{{ ___('Free') }}</td><td>{{ ___('This app is free to install.') }}</td></tr>
            @elseif($app->billing_type === 'recurring')
                <tr><th>{{ ___('Plan') }}</th><td class="amt">{{ price_symbol_format($app->price) }}</td>
                    <td>{{ (int) $app->billing_period_days === 30 ? ___('Billed monthly') : ___('Billed every :days days', ['days' => (int) $app->billing_period_days]) }}</td></tr>
            @else
                <tr><th>{{ ___('Base') }}</th><td class="amt">{{ (float) $app->price > 0 ? price_symbol_format($app->price) : ___('Free') }}</td><td></td></tr>
                <tr><th>{{ ___('Usage') }}</th><td class="amt">{{ price_symbol_format($app->usage_price) }}</td>
                    <td>{{ ___('per :unit', ['unit' => $app->usage_unit]) }}</td></tr>
            @endif
        </table>
    </section>

    {{-- Scopes --}}
    <section class="block">
        <h2>{{ ___('This app can access:') }}</h2>
        @if($scopes->isEmpty())
            <p class="msg" style="margin:0;color:var(--muted);">{{ ___('No account access requested.') }}</p>
        @else
            <ul class="scopes">
                @foreach($scopes as $s)
                    <li><span>{{ $s['label'] }}</span><span class="key">{{ $s['key'] }}</span></li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Developer / support / docs --}}
    <section class="block">
        <h2>{{ ___('Developer') }}</h2>
        <div class="devgrid">
            <div><div style="color:var(--muted);font-size:13px;">{{ ___('Built by') }}</div>{{ $devName }}</div>
            @if($app->support_url)<div><div style="color:var(--muted);font-size:13px;">{{ ___('Support') }}</div><a href="{{ $app->support_url }}" target="_blank" rel="noopener nofollow">{{ ___('Contact support') }} →</a></div>@endif
            @if($app->docs_url)<div><div style="color:var(--muted);font-size:13px;">{{ ___('Documentation') }}</div><a href="{{ $app->docs_url }}" target="_blank" rel="noopener nofollow">{{ ___('Read the docs') }} →</a></div>@endif
        </div>
    </section>

    @include('marketplace._review_list', [
        'avg' => (float) $app->rating_avg,
        'count' => (int) $app->rating_count,
        'distribution' => $distribution,
        'reviews' => $publishedReviews,
    ])

    @include('marketplace._review_form', [
        'action' => route('apps.store.review', $app->slug),
        'eligible' => $eligibleToReview,
        'myReview' => $myReview,
        'ineligibleHint' => ___('Only users who installed this app can review it.'),
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
(function(){
    const btn = document.getElementById('installBtn');
    if (!btn) return;
    const msg = document.getElementById('msg');
    btn.addEventListener('click', async function(){
        this.disabled = true;
        msg.textContent = '{{ ___('Installing…') }}';
        try {
            const r = await fetch(btn.dataset.install, { method:'POST', headers:{ 'X-CSRF-TOKEN':'{{ csrf_token() }}', Accept:'application/json' } });
            const d = await r.json();
            if (d.ok) { btn.remove(); msg.textContent=''; document.getElementById('postInstall').hidden = false; }
            else { msg.textContent = d.message || '{{ ___('Install failed.') }}'; this.disabled = false; }
        } catch(e){ msg.textContent = '{{ ___('Install failed.') }}'; this.disabled = false; }
    });
})();
</script>
@endauth
</body>
</html>
