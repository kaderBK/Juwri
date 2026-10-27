{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  <x-demo-frame /> — shown only while demo mode is on (settings → demo_mode).
  Keeps the demo out of third-party iframes and shows a slim bar naming the
  product with an optional purchase link (config appinfo.buy_now_url).
--}}
@if (demo_mode())
    @php($buyUrl = (string) config('appinfo.buy_now_url', ''))
    <script nonce="{{ csp_nonce() }}">
        if (window.top !== window.self) { window.top.location.href = window.location.href; }
    </script>
    <style nonce="{{ csp_nonce() }}">
        .demo-bar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .5rem 1rem;
            padding: .6rem 1rem; background: #111827; color: #f9fafb; font-size: .85rem; line-height: 1.3; }
        .demo-bar__brand { display: inline-flex; align-items: center; gap: .5rem; color: inherit; text-decoration: none; font-weight: 600; }
        .demo-bar__tag { padding: .1rem .45rem; border-radius: 999px; background: #f59e0b; color: #111827; font-size: .7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
        .demo-bar__cta { display: inline-flex; align-items: center; gap: .4rem; padding: .4rem .9rem; border-radius: .5rem;
            background: #f9fafb; color: #111827; text-decoration: none; font-weight: 600; }
        .demo-bar__cta:hover, .demo-bar__cta:focus-visible { background: #e5e7eb; }
    </style>
    <div class="demo-bar" role="note">
        <a class="demo-bar__brand" href="{{ url('/') }}">
            <span class="demo-bar__tag">{{ ___('Demo') }}</span>
            <span>{{ ($settings->site_title ?? null) ?: config('app.name') }}</span>
        </a>
        @if ($buyUrl !== '')
            <a class="demo-bar__cta" href="{{ $buyUrl }}" rel="noopener">
                <x-ui.icon name="shopping-cart" size="16" />{{ ___('Buy Now') }}
            </a>
        @endif
    </div>
@endif
