{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.folio.layout')

@php
    /* Folio option contract (rev/folio-theme/SPEC.md §3). Every read is
       sparse-safe: a fresh store with ZERO options set must render sanely. */
    $folioSource = (($postOptions->restaurant_folio_source ?? 'images') === 'pdf') ? 'pdf' : 'images';
    $folioPdf    = (string) ($postOptions->restaurant_folio_pdf ?? '');
    $folioIsPdf  = $folioSource === 'pdf' && $folioPdf !== '';
    $folioPdfUrl = $folioIsPdf ? asset('storage/'.$folioPdf) : null;
    if (! $folioIsPdf) {
        // source=pdf with no uploaded file degrades to the images pipeline
        // (or its empty state) instead of a book that can never load.
        $folioSource = 'images';
    }

    /* §12: when B5 has pre-rastered the uploaded PDF server-side, serve those
       page images through the IMAGES pipeline (the client never loads pdf.js).
       Guarded — an absent helper keeps today's client-side pdf.js path. */
    $folioPdfPages  = function_exists('folio_pdf_pages') ? folio_pdf_pages($store->id) : [];
    $folioPreRaster = $folioIsPdf && ! empty($folioPdfPages);
    if ($folioPreRaster) {
        $folioSource = 'images';
    }
    /* "Server page list" = rendered from a server-side page array (images OR a
       pre-rastered PDF). Drives list view, counter, og — NOT the legacy PDF. */
    $folioServerPages = ! $folioIsPdf || $folioPreRaster;

    $folioDisplay = (string) ($postOptions->restaurant_folio_display ?? 'auto');
    if (! in_array($folioDisplay, ['auto', 'single', 'double'], true)) {
        $folioDisplay = 'auto';
    }
    $folioHardcover = ! empty($postOptions->restaurant_folio_hardcover);
    $folioAnim = ((($postOptions->restaurant_folio_anim ?? 'flip')) === 'swipe') ? 'swipe' : 'flip';

    /* A6 ambient texture (whitelist, same idiom as $folioDisplay above) +
       page-turn sound toggle ('1' is the only on value). */
    $folioTexture = (string) ($postOptions->restaurant_folio_texture ?? '');
    if (! in_array($folioTexture, ['linen', 'wood', 'marble', 'paper'], true)) {
        $folioTexture = '';
    }
    $folioSound = ((string) ($postOptions->restaurant_folio_sound ?? '')) === '1' ? '1' : '0';

    /* Toolbar toggles: missing/empty ⇒ default; '0' is the only off value. */
    $folioOn = function (string $key, bool $default) use ($postOptions): bool {
        $v = (string) ($postOptions->$key ?? '');
        return $v === '' ? $default : $v !== '0';
    };
    $folioTbThumbs     = $folioOn('restaurant_folio_tb_thumbs', true);
    $folioTbZoom       = $folioOn('restaurant_folio_tb_zoom', true);
    $folioTbFullscreen = $folioOn('restaurant_folio_tb_fullscreen', true);
    $folioTbDownload   = $folioOn('restaurant_folio_tb_download', false) && $folioIsPdf;

    /* Colors: strict hex or the spec default — never echo arbitrary strings
       into a style attribute. */
    $folioHex = function ($v, string $fallback): string {
        $v = (string) ($v ?? '');
        return preg_match('/^#[0-9a-fA-F]{3,8}$/', $v) === 1 ? $v : $fallback;
    };
    $folioBg     = $folioHex($postOptions->restaurant_folio_bg ?? null, '#1f2430');
    $folioAccent = $folioHex($postOptions->restaurant_folio_accent ?? null, '#6d28d9');

    $folioRtl = (optional(current_language())->direction ?? 'ltr') === 'rtl';

    /* Page list (images mode): ONE logical-order source of truth. Ordered
       active image_menus, capped by the owner's menu entitlement (999 =
       unlimited sentinel), then (SPEC §8) filtered by the active book when the
       guarded backend helpers exist. Every consumer — book, thumbnails, list
       view, section chips, hotspots — derives from $folioVisiblePages so the
       cap + filter are computed exactly once. The PDF pipeline renders
       client-side from $folioPdfUrl and has no server page list. */
    $folioActiveBook   = function_exists('folio_active_book') ? folio_active_book($store->id) : null;
    $folioPageBookTags = function_exists('folio_page_book_tags') ? folio_page_book_tags($store->id) : [];
    $folioBooks        = function_exists('folio_books') ? folio_books($store->id) : [];

    /* A8: ?book=<key> overrides the time-resolved active book, validated
       against the defined keys. Invalid/absent = keep the resolved book. The
       page-list loop below filters on the final $folioActiveBook. */
    $folioBookParam = request()->query('book');
    if (is_string($folioBookParam) && $folioBookParam !== '' && ! empty($folioBooks)) {
        if (in_array($folioBookParam, array_column($folioBooks, 'key'), true)) {
            $folioActiveBook = $folioBookParam;
        }
    }

    /* A8: order CTA (guarded). Non-null → a toolbar button linking out. */
    $folioCta = function_exists('folio_cta') ? folio_cta($store) : null;

    $folioVisiblePages = [];
    if ($folioPreRaster) {
        // A PDF is the whole book (SPEC §3): no entitlement cap, no book filter.
        foreach ($folioPdfPages as $folioPdfPageUrl) {
            $folioVisiblePages[] = [
                'id'     => 0,
                'image'  => '',
                'url'    => (string) $folioPdfPageUrl,
                'name'   => '',
                'srcset' => null,
            ];
        }
    } elseif (! $folioIsPdf) {
        $folioMenuLimit = effective_limit('menu', $store->user);
        $folioUnlimited = \App\Services\Entitlements::isUnlimitedSentinel($folioMenuLimit);
        foreach ($store->image_menus->where('active', '1')->values() as $folioKey => $folioMenu) {
            // Entitlement cap first (first N active pages) — exactly like today.
            if (! $folioUnlimited && $folioKey >= $folioMenuLimit) {
                break;
            }
            // Book filter: a page tagged to a DIFFERENT active book is dropped;
            // untagged pages always show; no active book (or no helper) = all.
            if ($folioActiveBook !== null) {
                $folioTag = $folioPageBookTags[$folioMenu->id] ?? null;
                if ($folioTag !== null && $folioTag !== $folioActiveBook) {
                    continue;
                }
            }
            $folioVisiblePages[] = [
                'id'     => (int) $folioMenu->id,
                'image'  => (string) $folioMenu->image,
                'url'    => media_url('storage/menu/', $folioMenu->image, $store->id ?? null),
                'name'   => (string) $folioMenu->name,
                'srcset' => function_exists('folio_srcset') ? folio_srcset($folioMenu->image, $store->id ?? null) : null,
            ];
        }
    }

    $folioHasContent = $folioIsPdf || count($folioVisiblePages) > 0;

    /* First visible page (logical) → og:image; store logo is the fallback. */
    $folioOgImage = '';
    if (count($folioVisiblePages) > 0) {
        $folioOgImage = $folioVisiblePages[0]['url'];
    } elseif ($store->main_image && $store->main_image !== 'default.png') {
        $folioOgImage = asset('storage/store/logo/'.$store->main_image);
    }

    /* Config page list feeds the CANVAS book + thumbnails. StPageFlip has no
       native RTL, so the array is reversed (book opens right-to-left, opening
       on the last index); folio.js mirrors the controls and maps logical↔array
       (SPEC §4). DB ids never leave the server — config carries url/name only
       (+ srcset when a variant exists). */
    $folioBookPages = $folioRtl ? array_reverse($folioVisiblePages) : $folioVisiblePages;
    $folioConfigPages = [];
    foreach ($folioBookPages as $folioBp) {
        $folioConfigPages[] = $folioBp['srcset'] !== null
            ? ['url' => $folioBp['url'], 'name' => $folioBp['name'], 'srcset' => $folioBp['srcset']]
            : ['url' => $folioBp['url'], 'name' => $folioBp['name']];
    }

    /* Responsive variant map (original→variant URL) for the CANVAS book images,
       read from the guarded srcset helper's 900w candidate (the list imgs +
       thumbs use srcset directly). Absent helper = no key; folio.js swaps to
       variants only for ≤900px viewports. */
    $folioPageVariants = null;
    if (function_exists('folio_srcset')) {
        $folioVarMap = [];
        foreach ($folioVisiblePages as $folioVp) {
            if (! $folioVp['srcset']) {
                continue;
            }
            foreach (explode(',', $folioVp['srcset']) as $folioCand) {
                $folioCand = trim($folioCand);
                if (preg_match('/\s900w$/', $folioCand)) {
                    $folioVarMap[$folioVp['url']] = trim(preg_replace('/\s+\S+$/', '', $folioCand));
                    break;
                }
            }
        }
        if (! empty($folioVarMap)) {
            $folioPageVariants = $folioVarMap;
        }
    }

    $folioConfig = [
        'source'    => $folioSource,
        'pdfUrl'    => $folioPdfUrl,
        'display'   => $folioDisplay,
        'hardcover' => $folioHardcover,
        'anim'      => $folioAnim,
        'rtl'       => $folioRtl,
        'pages'     => $folioConfigPages,
        'labels'    => [
            'page'      => ___('Page'),
            'loadError' => ___('This menu could not be loaded. Please try again later.'),
            'hint'      => ___('Swipe or tap to turn the page'),
            'close'     => ___('Close'),
            'soldOut'   => ___('Sold out'),
            'search'    => ___('Search menu'),
        ],
    ];
    if ($folioPageVariants !== null) {
        $folioConfig['pageVariants'] = $folioPageVariants;
    }

    /* A8 book switcher: folio.js renders chips when ≥2 books. Absent helper
       ⇒ no key (single-book/legacy behavior). */
    if (function_exists('folio_books')) {
        $folioConfig['books'] = ['list' => $folioBooks, 'active' => $folioActiveBook];
    }

    /* A9 metrics beacon: the route name resolves only after B8 merges. Null
       pre-merge (Route::has guard) → folio.js sends nothing. */
    $folioConfig['metricsUrl'] = \Illuminate\Support\Facades\Route::has('folio.metrics')
        ? route('folio.metrics', $store->id)
        : null;

    /* A7 hotspots (SPEC §7): guarded payload remapped so `spots` is keyed by
       the LOGICAL page index of the visible list — DB image ids never reach
       the client. Absent helper / no spots ⇒ no key (folio.js stays inert). */
    if (function_exists('folio_hotspots_payload')) {
        $folioHotspots = folio_hotspots_payload($store);
        $folioSpotsByLogical = [];
        foreach ($folioVisiblePages as $folioLogical => $folioVp) {
            if (! empty($folioHotspots['spots'][$folioVp['id']])) {
                $folioSpotsByLogical[$folioLogical] = $folioHotspots['spots'][$folioVp['id']];
            }
        }
        if (! empty($folioSpotsByLogical)) {
            $folioConfig['hotspots'] = [
                'spots' => $folioSpotsByLogical,
                'items' => $folioHotspots['items'] ?? [],
            ];
        }
    }
@endphp

@push('style')
    <link rel="stylesheet"
          href="{{ asset('assets/store_templates/folio/css/folio.css?ver='.config('appinfo.version').'.'.@filemtime(public_path('assets/store_templates/folio/css/folio.css'))) }}">
@endpush

@push('meta')
    {{-- A5 SEO: social preview. First visible page (logical) is the hero image,
         store logo the fallback; title is the store name. --}}
    <meta property="og:title" content="{{ $store->title }}">
    @if($folioOgImage !== '')
        <meta property="og:image" content="{{ $folioOgImage }}">
    @endif
@endpush

@section('content')
<div id="folioTheme"
     data-folio-source="{{ $folioSource }}"
     data-folio-display="{{ $folioDisplay }}"
     data-folio-anim="{{ $folioAnim }}"
     data-folio-hardcover="{{ $folioHardcover ? '1' : '0' }}"
     data-folio-texture="{{ $folioTexture }}"
     data-folio-sound="{{ $folioSound }}"
     @if($folioRtl) data-folio-rtl="1" @endif
     style="--folio-bg: {{ $folioBg }}; --folio-accent: {{ $folioAccent }};">

    <header class="folio-head">
        @if($store->main_image && $store->main_image !== 'default.png')
            <img class="folio-logo" src="{{ asset('storage/store/logo/'.$store->main_image) }}" alt="{{ $store->title }}">
        @endif
        <div class="folio-head-txt">
            <h1>{{ $store->title }}</h1>
            @if($store->sub_title)<p>{{ $store->sub_title }}</p>@endif
        </div>
    </header>

    @if(! $folioHasContent)
        {{-- Empty state: no pages and no PDF — a fresh store. No book JS runs. --}}
        <div class="folio-empty" role="status">
            <svg class="folio-empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <path d="M12 5.5C10.4 4 8 3.5 5 3.5c-.6 0-1 .4-1 1v13c0 .6.4 1 1 1 3 0 5.4.5 7 2 1.6-1.5 4-2 7-2 .6 0 1-.4 1-1v-13c0-.6-.4-1-1-1-3 0-5.4.5-7 2Z"/>
                <path d="M12 5.5v15"/>
            </svg>
            <h2>{{ ___('Menu coming soon') }}</h2>
            <p>{{ ___('This menu has no pages yet. Please check back later.') }}</p>
        </div>
    @else
        <main class="folio-main" aria-label="{{ $store->title }}">
            <div class="folio-stage" id="folioStage">
                <div class="folio-loader" id="folioLoader" role="status" aria-label="{{ ___('Loading') }}">
                    <span class="folio-spin" aria-hidden="true"></span>
                </div>
                <div class="folio-errorpanel" id="folioError" hidden>
                    <p>{{ ___('This menu could not be loaded. Please try again later.') }}</p>
                </div>
                <div class="folio-zoomwrap" id="folioZoomWrap">
                    <div class="folio-book" id="folioBook"></div>
                </div>
                <div class="folio-pan" id="folioPan" hidden></div>
            </div>

            <nav class="folio-toolbar" aria-label="{{ ___('Book controls') }}">
                @if($folioTbThumbs)
                    <div class="folio-thumbs" id="folioThumbs" aria-label="{{ ___('Pages') }}"></div>
                @endif
                <div class="folio-tb-row">
                    <button type="button" class="folio-btn folio-btn-dir" id="folioFirst" aria-label="{{ ___('First page') }}">&laquo;</button>
                    <button type="button" class="folio-btn folio-btn-dir" id="folioPrev" aria-label="{{ ___('Previous page') }}">&lsaquo;</button>
                    <span class="folio-counter" aria-live="polite"><span id="folioCur">1</span><span aria-hidden="true"> / </span><span id="folioTotal">{{ $folioServerPages ? count($folioVisiblePages) : '–' }}</span></span>
                    <button type="button" class="folio-btn folio-btn-dir" id="folioNext" aria-label="{{ ___('Next page') }}">&rsaquo;</button>
                    <button type="button" class="folio-btn folio-btn-dir" id="folioLast" aria-label="{{ ___('Last page') }}">&raquo;</button>
                    @if($folioTbZoom)
                        <span class="folio-tb-sep" aria-hidden="true"></span>
                        <button type="button" class="folio-btn folio-btn-zoom" id="folioZoom" aria-label="{{ ___('Zoom') }}">1&times;</button>
                    @endif
                    @if($folioTbFullscreen)
                        <button type="button" class="folio-btn" id="folioFull" aria-label="{{ ___('Fullscreen') }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/></svg>
                        </button>
                    @endif
                    @if($folioServerPages)
                        {{-- A5: view-as-list toggle. JS swaps stage⇄list; without JS
                             the noscript style below already shows the list. --}}
                        <button type="button" class="folio-btn folio-btn-list" id="folioListToggle" aria-pressed="false" aria-label="{{ ___('View as list') }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>
                        </button>
                    @endif
                    @if($folioTbDownload)
                        <a class="folio-btn" id="folioDownload" href="{{ $folioPdfUrl }}" download aria-label="{{ ___('Download menu') }}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4v11m0 0 4-4m-4 4-4-4M5 20h14"/></svg>
                        </a>
                    @endif
                    @if(is_array($folioCta) && ! empty($folioCta['href']))
                        {{-- A8 order CTA: helper-built wa.me / validated owner link. --}}
                        <a class="folio-btn folio-btn-cta" href="{{ $folioCta['href'] }}" target="_blank" rel="noopener">{{ $folioCta['label'] ?? ___('Order') }}</a>
                    @endif
                </div>
            </nav>
        </main>

        @if($folioServerPages && count($folioVisiblePages) > 0)
            {{-- A5 view-as-list + SEO: a crawlable, JS-free stacked list of the
                 SAME entitlement-capped + book-filtered pages in LOGICAL order.
                 JS toggles it against the stage; the noscript style below reveals
                 it and hides the book when scripting is off. --}}
            <noscript>
                <style nonce="{{ csp_nonce() }}">
                    #folioTheme .folio-stage { display: none; }
                    #folioTheme .folio-toolbar .folio-tb-row,
                    #folioTheme .folio-chips,
                    #folioTheme .folio-thumbs { display: none; }
                    #folioTheme .folio-list { display: flex; }
                </style>
            </noscript>
            <section class="folio-list" id="folioList" hidden aria-label="{{ $store->title }}">
                @foreach($folioVisiblePages as $folioLi => $folioListPage)
                    <img class="folio-list-img"
                         src="{{ $folioListPage['url'] }}"
                         @if($folioListPage['srcset']) srcset="{{ $folioListPage['srcset'] }}" @endif
                         loading="{{ $folioLi < 2 ? 'eager' : 'lazy' }}"
                         decoding="async"
                         alt="{{ $folioListPage['name'] !== '' ? $folioListPage['name'] : ___('Page').' '.($folioLi + 1) }}"
                         draggable="false">
                @endforeach
            </section>
        @endif
    @endif

    <footer class="folio-foot">
        <p>&copy; {{ date('Y') }} {{ $store->title }}
            @if(! store_plan_allows($store, 'hide_branding'))
                &middot; <a href="{{ route('home') }}" target="_blank" rel="noopener">{{ ___('Provided by') }} {{ config('settings.site_title') ?: config('app.name') }}</a>
            @endif
        </p>
    </footer>
</div>
{{-- Non-executing JSON config for folio.js (nonce satisfies the CSP ratchet lint). --}}
<script type="application/json" id="folioConfig" nonce="{{ csp_nonce() }}">@json($folioConfig)</script>
@endsection

@push('script')
    <script src="{{ asset('assets/global/page-flip/page-flip.browser.js') }}"></script>
    @if($folioIsPdf && ! $folioPreRaster)
        {{-- Legacy client-side PDF path only. Pre-rastered PDFs (§12) render as
             images, so pdf.js is skipped. --}}
        <script src="{{ asset('assets/global/pdfjs/pdf.min.js') }}"></script>
        <script src="{{ asset('assets/global/pdfjs/render-pdf.js') }}"></script>
    @endif
    <script src="{{ asset('assets/store_templates/folio/js/folio.js?ver='.config('appinfo.version').'.'.@filemtime(public_path('assets/store_templates/folio/js/folio.js'))) }}"></script>
@endpush
