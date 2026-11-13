{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Marketplace chrome: top navigation. Category-first, account-last (Shopify
     App Store pattern) + language picker (endonyms) + dark-mode toggle.
     Self-contained styles, .as- prefix, logical CSS for RTL. Shared by
     /apps/store and /marketplace pages ($asNavActive: 'apps'|'themes'). --}}
<script nonce="{{ csp_nonce() }}">
    /* Pre-paint theme boot: saved choice, else OS preference. */
    (function(){
        try {
            var t = localStorage.getItem('as-theme');
            if (!t) t = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            document.documentElement.dataset.theme = t;
        } catch(e){}
    })();
</script>
{{-- Site typography (Syne + Instrument Sans, assets/fonts). --}}
<link rel="stylesheet" href="{{ asset('assets/fonts/fonts.css') }}">
@php
    /* Reseller-aware primary: reseller primary_color → settings.theme_color →
       indigo default. Validated as a hex so it's safe to inline into CSS. */
    $asPrimary = brand_context()->primaryColor();
    if (! is_string($asPrimary) || ! preg_match('/^#[0-9a-fA-F]{3,8}$/', $asPrimary)) {
        $asPrimary = '#8b3dff';
    }
@endphp
<style nonce="{{ csp_nonce() }}">
    :root {
        --accent: {{ $asPrimary }};
        --accent-ink: color-mix(in srgb, {{ $asPrimary }} 70%, #1a1030);
        --chip:#f3f2f7;
    }
    :root[data-theme="dark"] {
        --bg:#0c0b12; --panel:#17151f; --ink:#e9e8ef; --muted:#a7a5b3;
        --line:#272334; --accent: {{ $asPrimary }};
        --accent-ink: color-mix(in srgb, {{ $asPrimary }} 55%, #ffffff);
        --star:#ffc107; --ok:#4cc38a; --chip:#100e18;
    }
    .as-nav { background:var(--panel); border-bottom:1px solid var(--line); position:sticky; top:0; z-index:40; }
    .as-nav .as-row { max-width:1180px; margin:0 auto; padding-inline:24px; display:flex; align-items:center; gap:18px; min-height:62px; }
    .as-nav .as-brand { display:flex; align-items:center; gap:10px; font-weight:800; font-size:17px; color:var(--ink); white-space:nowrap; }
    .as-nav .as-brand img { height:30px; width:auto; display:block; }
    .as-nav .as-links { display:flex; align-items:center; gap:4px; overflow-x:auto; scrollbar-width:none; flex:1; }
    .as-nav .as-links::-webkit-scrollbar { display:none; }
    .as-nav .as-links a { padding:8px 13px; border-radius:999px; font-size:14.5px; color:var(--muted); white-space:nowrap; }
    .as-nav .as-links a:hover { color:var(--ink); background:var(--bg); }
    .as-nav .as-links a.active { color:var(--accent-ink); background:var(--bg); font-weight:700; }
    .as-nav .as-auth { display:flex; align-items:center; gap:8px; white-space:nowrap; }
    .as-nav .as-auth .as-login { font-size:14.5px; color:var(--muted); }
    .as-nav .as-auth .as-cta { background:var(--accent); color:#fff; font-weight:700; font-size:14px; padding:9px 18px; border-radius:999px; }
    .as-iconbtn { display:inline-flex; align-items:center; gap:5px; border:1px solid var(--line); background:transparent; color:var(--muted); border-radius:999px; padding:7px 11px; cursor:pointer; font:inherit; font-size:13px; }
    .as-iconbtn:hover { color:var(--ink); border-color:var(--accent); }
    .as-iconbtn svg { width:17px; height:17px; fill:none; stroke:currentColor; stroke-width:1.8; stroke-linecap:round; stroke-linejoin:round; }
    .as-langpick { position:relative; }
    .as-langmenu { position:absolute; inset-inline-end:0; top:calc(100% + 8px); min-width:190px; max-height:330px; overflow-y:auto; background:var(--panel); border:1px solid var(--line); border-radius:14px; box-shadow:0 12px 34px rgba(0,0,0,.16); padding:6px; z-index:60; }
    .as-langmenu a { display:flex; align-items:center; gap:10px; padding:8px 11px; border-radius:9px; font-size:14px; color:var(--ink); }
    .as-langmenu a:hover { background:var(--bg); }
    .as-langmenu a.is-on { color:var(--accent-ink); font-weight:700; }
    .as-langmenu .code { font-size:11.5px; font-weight:700; color:var(--muted); background:var(--bg); border-radius:6px; padding:1px 6px; min-width:26px; text-align:center; }
    .as-langmenu a.is-on .code { color:var(--accent-ink); }
    #as-sun { display:none; }
    :root[data-theme="dark"] #as-sun { display:block; }
    :root[data-theme="dark"] #as-moon { display:none; }
    @media (max-width:720px){ .as-nav .as-auth .as-login { display:none; } .as-nav .as-row { gap:10px; } }
</style>
@php
    /* brand_logo() is a bare filename for the platform (reseller logos are full URLs). */
    $asLogo = brand_logo();
    if ($asLogo && ! str_starts_with($asLogo, 'http') && ! str_starts_with($asLogo, '/')) {
        $asLogo = asset('storage/logo/' . $asLogo);
    }
    /* Endonyms — DB Language.name mixes native and English; show each language
       in its own script (same fix as the Fusion picker). */
    $asEndonyms = [
        'en' => 'English', 'fr' => 'Français', 'de' => 'Deutsch', 'es' => 'Español',
        'zh' => '中文', 'ar' => 'العربية', 'ru' => 'Русский', 'hi' => 'हिन्दी',
        'tr' => 'Türkçe', 'ur' => 'اردو', 'pt' => 'Português', 'he' => 'עברית',
        'bn' => 'বাংলা', 'bg' => 'Български', 'ro' => 'Română', 'th' => 'ไทย', 'vi' => 'Tiếng Việt',
    ];
    try {
        $asLangs = \Juwri\Core\Models\Language::where('active', 1)->get(['code', 'name'])
            ->map(fn ($l) => (object) ['code' => $l->code, 'name' => $asEndonyms[$l->code] ?? $l->name])
            ->sortBy('name')->values();
    } catch (\Throwable $e) {
        $asLangs = collect();
    }
    /* store.lang sets session('locale') and bounces back — the only switch
       that works for these unprefixed pages in BOTH locale modes. */
@endphp
<nav class="as-nav">
    <div class="as-row">
        <a class="as-brand" href="{{ url('/') }}">
            @if($asLogo)<img src="{{ $asLogo }}" alt="{{ $siteName }}">@else{{ $siteName }}@endif
        </a>
        <div class="as-links">
            <a href="{{ route('apps.store.index') }}" class="{{ ($asNavActive ?? '') === 'apps' ? 'active' : '' }}">{{ ___('App Store') }}</a>
            <a href="{{ route('marketplace.index') }}" class="{{ ($asNavActive ?? '') === 'themes' ? 'active' : '' }}">{{ ___('Themes') }}</a>
            @if (Route::has('mk.home')){{-- P1-13 13d: the Fusion marketplace is an ee module --}}
            <a href="{{ url('/fusion') }}">{{ ___('Order food') }}</a>
            @endif
            <a href="{{ route('developers') }}">{{ ___('Developers') }}</a>
            @if (Route::has('partners.index')){{-- P1-13 13f: the reseller programme is the Reseller module's (ee) --}}
            <a href="{{ route('partners.index') }}">{{ ___('Partners') }}</a>
            @endif
        </div>
        <div class="as-auth">
            <button type="button" class="as-iconbtn" id="as-themebtn" aria-label="{{ ___('Toggle theme') }}">
                <svg id="as-moon" viewBox="0 0 24 24"><path d="M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8Z"/></svg>
                <svg id="as-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4.5"/><path d="M12 2v2.5M12 19.5V22M2 12h2.5M19.5 12H22M4.9 4.9l1.8 1.8M17.3 17.3l1.8 1.8M19.1 4.9l-1.8 1.8M6.7 17.3l-1.8 1.8"/></svg>
            </button>
            @if($asLangs->count() > 1)
                <div class="as-langpick" id="as-langpick">
                    <button type="button" class="as-iconbtn" id="as-langbtn" aria-haspopup="menu" aria-expanded="false" aria-label="{{ ___('Switch language') }}">
                        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.7 3.8 5.8 3.8 9s-1.3 6.3-3.8 9c-2.5-2.7-3.8-5.8-3.8-9S9.5 5.7 12 3Z"/></svg>
                        <b>{{ strtoupper(app()->getLocale()) }}</b>
                    </button>
                    <div class="as-langmenu" id="as-langmenu" role="menu" hidden>
                        @foreach($asLangs as $asLang)
                            <a href="{{ \Juwri\Core\Support\ViewHelpers::storeLangUrl($asLang->code) }}" role="menuitem"
                               class="{{ $asLang->code === app()->getLocale() ? 'is-on' : '' }}"
                               @if($asLang->code === app()->getLocale()) aria-current="true" @endif>
                                <span class="code">{{ strtoupper($asLang->code) }}</span>
                                <span>{{ $asLang->name }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
            @auth
                <a class="as-cta" href="{{ route('apps.page') }}">{{ ___('My apps') }}</a>
            @else
                <a class="as-login" href="{{ route('login') }}">{{ ___('Log in') }}</a>
                <a class="as-cta" href="{{ route('register') }}">{{ ___('Start for free') }}</a>
            @endauth
        </div>
    </div>
</nav>
<script nonce="{{ csp_nonce() }}">
    (function(){
        var tbtn = document.getElementById('as-themebtn');
        tbtn && tbtn.addEventListener('click', function(){
            var next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
            document.documentElement.dataset.theme = next;
            try { localStorage.setItem('as-theme', next); } catch(e){}
        });
        var pick = document.getElementById('as-langpick');
        if (pick) {
            var btn = document.getElementById('as-langbtn'), menu = document.getElementById('as-langmenu');
            btn.addEventListener('click', function(e){
                e.stopPropagation();
                var open = menu.hidden;
                menu.hidden = !open;
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
            document.addEventListener('click', function(e){
                if (!menu.hidden && !pick.contains(e.target)) { menu.hidden = true; btn.setAttribute('aria-expanded','false'); }
            });
            document.addEventListener('keydown', function(e){
                if (e.key === 'Escape' && !menu.hidden) { menu.hidden = true; btn.setAttribute('aria-expanded','false'); }
            });
        }
    })();
</script>
