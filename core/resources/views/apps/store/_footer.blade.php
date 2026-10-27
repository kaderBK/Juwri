{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- App Store chrome: footer. Four columns (categories / marketplace /
     resources / company) + legal strip, per app-marketplace convention. --}}
<style nonce="{{ csp_nonce() }}">
    .as-footer { background:var(--panel); border-top:1px solid var(--line); margin-top:40px; }
    .as-footer .as-cols { max-width:1180px; margin:0 auto; padding:44px 24px 28px; display:grid; grid-template-columns:1.3fr 1fr 1fr 1fr; gap:32px; }
    @media (max-width:820px){ .as-footer .as-cols { grid-template-columns:1fr 1fr; } }
    @media (max-width:480px){ .as-footer .as-cols { grid-template-columns:1fr; } }
    .as-footer h3 { font-size:12.5px; text-transform:uppercase; letter-spacing:.08em; color:var(--muted); margin:0 0 12px; }
    .as-footer .as-fbrand { font-weight:800; font-size:17px; color:var(--ink); margin-bottom:10px; }
    .as-footer .as-fbrand img { height:28px; width:auto; display:block; }
    .as-footer p.as-blurb { color:var(--muted); font-size:14px; margin:0; max-width:34ch; }
    .as-footer ul { list-style:none; margin:0; padding:0; }
    .as-footer ul li { padding-block:4.5px; }
    .as-footer ul a { color:var(--ink); font-size:14.5px; opacity:.85; }
    .as-footer ul a:hover { color:var(--accent-ink); opacity:1; }
    .as-footer .as-legal { border-top:1px solid var(--line); }
    .as-footer .as-legal .as-lrow { max-width:1180px; margin:0 auto; padding:16px 24px; display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap; color:var(--muted); font-size:13.5px; }
    .as-footer .as-legal a { color:var(--muted); }
    .as-footer .as-legal a:hover { color:var(--ink); }
</style>
<footer class="as-footer">
    <div class="as-cols">
        <div>
            @php
                $asFLogo = brand_logo();
                if ($asFLogo && ! str_starts_with($asFLogo, 'http') && ! str_starts_with($asFLogo, '/')) {
                    $asFLogo = asset('storage/logo/' . $asFLogo);
                }
            @endphp
            <div class="as-fbrand">
                @if($asFLogo)<img src="{{ $asFLogo }}" alt="{{ $siteName }}">@else{{ $siteName }}@endif
            </div>
            <p class="as-blurb">{{ ___('Apps and themes that power your restaurant — built on :site.', ['site' => $siteName]) }}</p>
        </div>
        <div>
            <h3>{{ ___('Categories') }}</h3>
            <ul>
                @foreach(config('marketplace.app_categories') as $footerCat)
                    <li><a href="{{ route('apps.store.index', ['category' => [$footerCat]]) }}">{{ ___(ucfirst(str_replace('_',' ',$footerCat))) }}</a></li>
                @endforeach
            </ul>
        </div>
        <div>
            <h3>{{ ___('Marketplace') }}</h3>
            <ul>
                <li><a href="{{ route('apps.store.index') }}">{{ ___('App Store') }}</a></li>
                <li><a href="{{ route('marketplace.index') }}">{{ ___('Theme Store') }}</a></li>
                @if (Route::has('mk.home')){{-- P1-13 13d: the Fusion marketplace is an ee module --}}
                <li><a href="{{ url('/fusion') }}">{{ ___('Order food') }}</a></li>
                @endif
                @if (Route::has('partners.index')){{-- P1-13 13f: the reseller programme is the Reseller module's (ee) --}}
                <li><a href="{{ route('partners.index') }}">{{ ___('Partners') }}</a></li>
                @endif
            </ul>
        </div>
        <div>
            <h3>{{ ___('Developers') }}</h3>
            <ul>
                <li><a href="{{ route('developers') }}">{{ ___('Build an app') }}</a></li>
                <li><a href="{{ route('developers') }}#docs">{{ ___('API documentation') }}</a></li>
                @if (Route::has('partners.signup'))
                <li><a href="{{ route('partners.signup') }}">{{ ___('Become a partner') }}</a></li>
                @endif
                @auth<li><a href="{{ route('apps.page') }}">{{ ___('My apps') }}</a></li>@endauth
            </ul>
        </div>
    </div>
    <div class="as-legal">
        <div class="as-lrow">
            <span>© {{ date('Y') }} {{ $siteName }}. {{ ___('All rights reserved.') }}</span>
            <span>
                <a href="{{ url('/') }}">{{ ___('Home') }}</a> ·
                <a href="{{ route('developers') }}">{{ ___('Developer login') }}</a> ·
                <a href="{{ route('login') }}">{{ ___('Sign in') }}</a>
            </span>
        </div>
    </div>
</footer>
