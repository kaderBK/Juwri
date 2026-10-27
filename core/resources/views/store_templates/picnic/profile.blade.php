{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.layout')

@php
    use Illuminate\Support\Str;
    $curSign = cur_isolate($postOptions->currency_sign ?? '$');
    $curPos  = (string) currency_pos_effective($postOptions->id ?? null);
    $formatPrice = new \Juwri\Core\Support\RawPriceFormatter($curSign, $curPos, currency_decimals($postOptions->currency_code ?? null));
@endphp

@push('style_at_top')
    {{-- no web-font CDN: blocked by CSP, see index.blade.php --}}
    <link rel="stylesheet" href="{{ asset('assets/store_templates/'.$theme.'/css/style.css?ver='.config('appinfo.version').'.'.@filemtime(public_path('assets/store_templates/'.$theme.'/css/style.css'))) }}">
    <style nonce="{{ csp_nonce() }}">.cv-orders-link{display:flex;align-items:center;justify-content:center;gap:9px;width:100%;margin:14px 0 2px}.cv-orders-link .ic{width:18px;height:18px;flex:none}</style>
@endpush

@section('content')
<div class="picnic" id="picnic">
    <main class="views">
        <section class="view is-active" data-view="profile">
            <div class="view-scroll">
                <header class="page-head sub">
                    <a class="fab back inline" href="{{ storefront_localized_link(route('publicView', $store->slug)) }}" aria-label="{{ ___('Back to Menu') }}">
                        <svg class="ic" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></svg>
                    </a>
                    <h1>{{ ___('My Account') }}</h1>
                </header>

                <div class="me-card">
                    <span class="me-avatar">{{ Str::upper(Str::substr($customer->name, 0, 1)) }}</span>
                    <div>
                        <b>{{ $customer->name }}</b>
                        <p class="tk-label">{{ $customer->phone ?? $customer->email }}</p>
                    </div>
                    <button class="minibtn" id="cvLogoutBtn" type="button">{{ ___('Logout') }}</button>
                </div>

                <a class="ghostbtn cv-orders-link" href="{{ route('customer.account', $store->id) }}"><svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2h9l3 3v17H6V2Z"/><path d="M9 8h6M9 12h6M9 16h4"/></svg><span>{{ ___('My Orders') }}</span></a>

                @include('store_templates._partials.account-extras')

                <div class="tail-space tall"></div>
            </div>
        </section>
    </main>
    <div class="toast" id="cvToast" role="status"></div>
</div>
@endsection

@push('scripts_at_bottom')
<script nonce="{{ csp_nonce() }}">
(function () {
    "use strict";
    var csrf = @json(csrf_token());
    var menuUrl = @json(storefront_localized_link(route('publicView', $store->slug)));

    var logout = document.getElementById('cvLogoutBtn');
    if (logout) logout.addEventListener('click', function () {
        fetch(@json(route('customer.logout', $store->id)), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        }).then(function () { window.location.href = menuUrl; });
    });
})();
</script>
@endpush
