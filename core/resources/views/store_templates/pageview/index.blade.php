{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.pageview.layout')

@php
    $items      = collect($total_menus ?? [])->values();
    $byCat      = $items->groupBy('category_id');
    $categories = collect($store->menu_categories ?? []);
    // Only categories that actually have items become pages.
    $pageCats   = $categories->filter(fn ($c) => ($byCat[$c->id] ?? collect())->isNotEmpty())->values();
    $primary    = $store->color ?: '#7c5cff';
    // Mode B: display an uploaded PDF instead of data-paged menu.
    $pvSource   = $postOptions->restaurant_pageview_source ?? 'data';
    $pvPdf      = $postOptions->restaurant_pageview_pdf ?? null;
    $isPdf      = $pvSource === 'pdf' && !empty($pvPdf);
    $pdfUrl     = $isPdf ? asset('storage/'.$pvPdf) : null;
@endphp

@push('style')
<style nonce="{{ csp_nonce() }}">
    :root { --pv-primary: {{ $primary }}; --pv-bg:#0e1014; --pv-paper:#faf7f1; --pv-ink:#211f26;
        --pv-muted:#8a8792; --pv-line:#e7e0d4; --pv-serif: Georgia,"Times New Roman","Noto Serif",serif; }
    * { box-sizing:border-box; }
    html { overflow-x:hidden; background:#0e1014; }
    .pv-body { margin:0; color:var(--pv-ink); overflow-x:hidden;
        background:radial-gradient(1100px 560px at 50% -12%, {{ $primary }}26, #0e1014 68%);
        font-family:'Saudi Riyal',-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,"Noto Sans",sans-serif; }
    .pv-skip { position:absolute; inset-inline-start:-999px; top:0; background:#000; color:#fff; padding:8px 16px; z-index:10; }
    .pv-skip:focus { inset-inline-start:0; }
    .pv-shell { min-height:100dvh; display:flex; flex-direction:column; align-items:center;
        justify-content:center; padding:16px 12px 92px; }
    .pv-stage { width:100%; max-width:720px; }
    .pv-page { display:none; background:var(--pv-paper); border-radius:16px; overflow-y:auto;
        box-shadow:0 24px 70px rgba(0,0,0,.5), 0 2px 0 rgba(255,255,255,.5) inset; min-height:min(78dvh,900px); max-height:82dvh;
        padding:44px 40px; animation:pv-in .26s cubic-bezier(.2,.7,.2,1); }
    .pv-page.is-active { display:block; }
    .pv-page.pv-cover.is-active { display:flex; }
    @media (prefers-reduced-motion:reduce){ .pv-page{ animation:none; } }
    @keyframes pv-in { from{ opacity:0; transform:translateY(10px) scale(.995);} to{ opacity:1; transform:none;} }
    /* cover */
    .pv-cover { flex-direction:column; align-items:center; justify-content:center; text-align:center; gap:16px; }
    .pv-eyebrow { text-transform:uppercase; letter-spacing:.38em; font-size:.72rem; font-weight:700; color:var(--pv-primary); }
    .pv-cover img { width:108px; height:108px; object-fit:cover; border-radius:50%;
        box-shadow:0 0 0 6px #fff, 0 12px 34px rgba(0,0,0,.28); }
    .pv-cover h1 { margin:0; font-family:var(--pv-serif); font-size:clamp(2.2rem,7vw,3rem); font-weight:700; line-height:1.03; letter-spacing:-.01em; }
    .pv-cover p { margin:0; color:var(--pv-muted); font-style:italic; }
    .pv-rule { width:64px; height:3px; border-radius:2px; background:var(--pv-primary); opacity:.9; }
    /* category */
    .pv-cat-head { margin:0 0 6px; padding-bottom:12px; border-bottom:1px solid var(--pv-line); }
    .pv-cat-eyebrow { display:block; text-transform:uppercase; letter-spacing:.24em; font-size:.68rem; font-weight:700; color:var(--pv-primary); margin-bottom:4px; }
    .pv-cat-title { margin:0; font-family:var(--pv-serif); font-size:1.75rem; font-weight:700; letter-spacing:-.01em; }
    .pv-list { list-style:none; margin:14px 0 0; padding:0; }
    .pv-row { display:flex; gap:14px; padding:15px 0; }
    .pv-row + .pv-row { border-top:1px solid #f0ebe0; }
    .pv-thumb { width:66px; height:66px; border-radius:12px; object-fit:cover; flex:0 0 auto; box-shadow:0 3px 10px rgba(0,0,0,.12); }
    .pv-rbody { flex:1; min-width:0; }
    .pv-name { font-weight:600; font-size:1.06rem; display:flex; align-items:baseline; gap:10px; }
    .pv-name > span:first-child { flex:0 1 auto; }
    .pv-dots { flex:1 1 auto; border-bottom:1px dotted #cabf9f; transform:translateY(-5px); min-width:18px; }
    .pv-price { color:var(--pv-primary); font-weight:700; white-space:nowrap; font-variant-numeric:tabular-nums; }
    .pv-desc { color:var(--pv-muted); font-size:.9rem; margin-top:4px; line-height:1.45; }
    .pv-soldout .pv-name { text-decoration:line-through; opacity:.5; }
    .pv-controls { position:fixed; inset-inline:0; bottom:0; display:flex; align-items:center; justify-content:center;
        gap:14px; padding:14px; background:linear-gradient(transparent,rgba(0,0,0,.5) 40%); z-index:5; }
    .pv-btn { width:52px; height:52px; border-radius:50%; border:0; background:#fff; color:var(--pv-ink);
        font-size:1.4rem; cursor:pointer; box-shadow:0 4px 14px rgba(0,0,0,.3); display:flex; align-items:center; justify-content:center; }
    .pv-btn:disabled { opacity:.4; cursor:default; }
    .pv-indicator { color:#fff; font-variant-numeric:tabular-nums; min-width:64px; text-align:center; }
    .pv-empty { color:#fff; text-align:center; padding:40px; }
    .pv-page.pv-zoomed { cursor:grab; }
    .pv-toc { position:fixed; inset-inline:0; bottom:84px; margin-inline:auto; max-width:520px; background:#fff; color:var(--pv-ink);
        border-radius:14px; box-shadow:0 -6px 30px rgba(0,0,0,.45); z-index:6; max-height:58dvh; overflow:auto; }
    .pv-toc[hidden] { display:none; }
    .pv-toc-head { display:flex; justify-content:space-between; align-items:center; padding:12px 16px;
        border-bottom:1px solid #eee; position:sticky; top:0; background:#fff; }
    .pv-toc-close { border:0; background:transparent; font-size:1.5rem; line-height:1; cursor:pointer; }
    .pv-toc-list { list-style:none; margin:0; padding:6px; }
    .pv-toc-list button { width:100%; text-align:start; border:0; background:transparent; padding:12px 14px;
        border-radius:8px; cursor:pointer; font:inherit; }
    .pv-toc-list button:hover, .pv-toc-list button:focus { background:#f2f0ea; }
    .pv-toc-list button[aria-current="true"] { color:var(--pv-primary); font-weight:700; }
</style>
@endpush

@section('content')
<div class="pv-shell">
    @if($pageCats->isEmpty() && !$isPdf)
        <p class="pv-empty">{{ ___('This menu has no items yet.') }}</p>
    @else
    <main id="pv-main" class="pv-stage" role="region" aria-roledescription="{{ ___('Menu pages') }}" aria-label="{{ $store->title }}" @if($isPdf) data-pdf-url="{{ $pdfUrl }}" @endif>
        @if($isPdf)
        {{-- Mode B: pages are rendered client-side from the uploaded PDF by pdf.js. --}}
        @else
        {{-- Cover page --}}
        <section class="pv-page pv-cover is-active" aria-label="{{ ___('Cover') }}">
            <span class="pv-eyebrow">{{ ___('Menu') }}</span>
            @if($store->main_image && $store->main_image !== 'default.png')
                <img src="{{ asset('storage/store/logo/'.$store->main_image) }}" alt="{{ $store->title }}">
            @endif
            <h1>{{ $store->title }}</h1>
            <span class="pv-rule"></span>
            @if($store->sub_title)<p>{{ $store->sub_title }}</p>@endif
        </section>

        {{-- One page per category --}}
        @foreach($pageCats as $cat)
            @php $catItems = $byCat[$cat->id] ?? collect(); @endphp
            <section class="pv-page" aria-label="{{ $cat->name }}">
                <div class="pv-cat-head">
                    <span class="pv-cat-eyebrow">{{ ___('Selection') }}</span>
                    <h2 class="pv-cat-title">{{ $cat->name }}</h2>
                </div>
                <ul class="pv-list">
                    @foreach($catItems as $menu)
                        <li class="pv-row {{ $menu->is_sold_out ? 'pv-soldout' : '' }}">
                            @if($menu->image && $menu->image !== 'default.png')
                                <img class="pv-thumb" src="{{ asset('storage/menu/'.$menu->image) }}" alt="{{ $menu->name }}" loading="lazy">
                            @endif
                            <div class="pv-rbody">
                                <div class="pv-name"><span>{{ $menu->name }}</span><span class="pv-dots"></span><span class="pv-price">{{ price_symbol_format($menu->price) }}</span></div>
                                @if($menu->description)
                                    <div class="pv-desc">{{ Str::limit(strip_tags($menu->description), 140) }}</div>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
        @endif
    </main>

    <nav class="pv-controls" aria-label="{{ ___('Page navigation') }}">
        <button class="pv-btn" id="pvToc" aria-label="{{ ___('Contents') }}" aria-expanded="false" title="{{ ___('Contents') }}">&#9776;</button>
        <button class="pv-btn" id="pvPrev" aria-label="{{ ___('Previous page') }}" rel="prev">&#8249;</button>
        <span class="pv-indicator" aria-live="polite"><span id="pvCur">1</span> / <span id="pvTotal">1</span></span>
        <button class="pv-btn" id="pvNext" aria-label="{{ ___('Next page') }}" rel="next">&#8250;</button>
        <button class="pv-btn" id="pvZoomOut" aria-label="{{ ___('Zoom out') }}" title="{{ ___('Zoom out') }}">&#8722;</button>
        <button class="pv-btn" id="pvZoomIn" aria-label="{{ ___('Zoom in') }}" title="{{ ___('Zoom in') }}">&#43;</button>
        <button class="pv-btn" id="pvFull" aria-label="{{ ___('Fullscreen') }}" title="{{ ___('Fullscreen') }}">&#9974;</button>
    </nav>

    <div class="pv-toc" id="pvTocPanel" role="dialog" aria-label="{{ ___('Contents') }}" aria-modal="false" hidden>
        <div class="pv-toc-head">
            <strong>{{ ___('Contents') }}</strong>
            <button class="pv-toc-close" id="pvTocClose" aria-label="{{ ___('Close') }}">&times;</button>
        </div>
        <ul class="pv-toc-list" id="pvTocList"></ul>
    </div>
    @endif
</div>
@endsection

@push('script')
@if($isPdf)
<script src="{{ asset('assets/global/pdfjs/render-pdf.js') }}"></script>
@endif
<script nonce="{{ csp_nonce() }}">
(function () {
    var stageEl = document.getElementById('pv-main');
    if (!stageEl) return;
    var pdfUrl = stageEl.getAttribute('data-pdf-url');
    if (pdfUrl && window.renderPdfUrlToPages) {
        window.renderPdfUrlToPages(pdfUrl, stageEl, { scale: 2 }).then(initPager).catch(initPager);
    } else {
        initPager();
    }

    function initPager() {
    var pages = Array.prototype.slice.call(document.querySelectorAll('.pv-page'));
    if (!pages.length) return;
    var rtl = (document.documentElement.getAttribute('dir') || '').toLowerCase() === 'rtl';
    var cur = 0;
    var prevBtn = document.getElementById('pvPrev');
    var nextBtn = document.getElementById('pvNext');
    var fullBtn = document.getElementById('pvFull');
    var curEl = document.getElementById('pvCur');
    document.getElementById('pvTotal').textContent = pages.length;

    var zoomInBtn = document.getElementById('pvZoomIn');
    var zoomOutBtn = document.getElementById('pvZoomOut');
    var tocBtn = document.getElementById('pvToc');
    var tocPanel = document.getElementById('pvTocPanel');
    var tocClose = document.getElementById('pvTocClose');
    var tocList = document.getElementById('pvTocList');
    var stage = document.getElementById('pv-main');
    var zoom = 1;
    var MIN = 1, MAX = 3;

    function applyZoom(z) {
        zoom = Math.max(MIN, Math.min(MAX, Math.round(z * 100) / 100));
        pages[cur].style.zoom = zoom;                    // grows the box so overflow can pan
        pages[cur].classList.toggle('pv-zoomed', zoom > 1);
        zoomOutBtn.disabled = zoom <= MIN;
        zoomInBtn.disabled = zoom >= MAX;
    }

    function show(i) {
        cur = Math.max(0, Math.min(pages.length - 1, i));
        pages.forEach(function (p, idx) { p.classList.toggle('is-active', idx === cur); p.style.zoom = 1; p.classList.remove('pv-zoomed'); });
        curEl.textContent = cur + 1;
        prevBtn.disabled = cur === 0;
        nextBtn.disabled = cur === pages.length - 1;
        pages[cur].scrollTop = 0;
        pages[cur].setAttribute('tabindex', '-1');
        zoom = 1; applyZoom(1);
        Array.prototype.forEach.call(tocList.children, function (li, idx) {
            var b = li.firstChild; if (b) b.setAttribute('aria-current', idx === cur ? 'true' : 'false');
        });
    }
    function next() { show(cur + 1); }
    function prev() { show(cur - 1); }

    prevBtn.addEventListener('click', prev);
    nextBtn.addEventListener('click', next);
    zoomInBtn.addEventListener('click', function () { applyZoom(zoom + 0.25); });
    zoomOutBtn.addEventListener('click', function () { applyZoom(zoom - 0.25); });
    fullBtn.addEventListener('click', function () {
        var el = document.documentElement;
        if (document.fullscreenElement) { document.exitFullscreen(); }
        else if (el.requestFullscreen) { el.requestFullscreen(); }
    });

    // Table of contents (jump to page).
    pages.forEach(function (p, idx) {
        var li = document.createElement('li');
        var b = document.createElement('button');
        b.type = 'button';
        b.textContent = p.getAttribute('aria-label') || ('Page ' + (idx + 1));
        b.addEventListener('click', function () { show(idx); toggleToc(false); });
        li.appendChild(b); tocList.appendChild(li);
    });
    function toggleToc(open) {
        var show = open === undefined ? tocPanel.hasAttribute('hidden') : open;
        if (show) { tocPanel.removeAttribute('hidden'); } else { tocPanel.setAttribute('hidden', ''); }
        tocBtn.setAttribute('aria-expanded', show ? 'true' : 'false');
    }
    tocBtn.addEventListener('click', function () { toggleToc(); });
    tocClose.addEventListener('click', function () { toggleToc(false); });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { toggleToc(false); return; }
        if (e.key === 'ArrowRight') { rtl ? prev() : next(); }
        else if (e.key === 'ArrowLeft') { rtl ? next() : prev(); }
        else if (e.key === 'Home') { show(0); }
        else if (e.key === 'End') { show(pages.length - 1); }
        else if (e.key === '+' || e.key === '=') { applyZoom(zoom + 0.25); }
        else if (e.key === '-') { applyZoom(zoom - 0.25); }
    });

    // Double-tap to toggle zoom.
    var lastTap = 0;
    stage.addEventListener('touchend', function () {
        var t = Date.now();
        if (t - lastTap < 300) { applyZoom(zoom > 1 ? 1 : 1.75); }
        lastTap = t;
    });

    // Pinch to zoom (two fingers).
    var pinchStart = null;
    stage.addEventListener('touchmove', function (e) {
        if (e.touches.length === 2) {
            var dx = e.touches[0].clientX - e.touches[1].clientX;
            var dy = e.touches[0].clientY - e.touches[1].clientY;
            var dist = Math.sqrt(dx * dx + dy * dy);
            if (pinchStart === null) { pinchStart = { dist: dist, zoom: zoom }; }
            else { applyZoom(pinchStart.zoom * (dist / pinchStart.dist)); }
        }
    }, { passive: true });
    stage.addEventListener('touchend', function (e) { if (e.touches.length < 2) pinchStart = null; });

    // Swipe to flip (single finger, only when not zoomed).
    var x0 = null, y0 = null;
    stage.addEventListener('touchstart', function (e) {
        if (e.touches.length === 1) { x0 = e.touches[0].clientX; y0 = e.touches[0].clientY; } else { x0 = null; }
    }, { passive: true });
    stage.addEventListener('touchend', function (e) {
        if (x0 === null || zoom > 1) { x0 = y0 = null; return; }  // zoomed = pan, not flip
        var dx = e.changedTouches[0].clientX - x0, dy = e.changedTouches[0].clientY - y0;
        if (Math.abs(dx) > 50 && Math.abs(dx) > Math.abs(dy)) {
            var swipeNext = dx < 0;
            if (rtl) swipeNext = !swipeNext;
            swipeNext ? next() : prev();
        }
        x0 = y0 = null;
    }, { passive: true });

    show(0);
    }
})();
</script>
@endpush
