{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- P6-45 (AUP 3.1): the public licence block of a published pharmacy: licence number, responsible pharmacist, CR number.
     Included at the end of every storefront layout (home, product page, track, ...). All data comes from PharmacyLicenceNotice's
     allow-list; renders nothing for any other store or an unpublished/unapproved pharmacy. Logical (RTL-safe) CSS only. --}}
@php
    $__plnStore = $store ?? null;
    $__plnNotice = null;
    if (is_object($__plnStore) && ! empty($__plnStore->id) && $__plnStore instanceof \Juwri\Core\Models\Store) {
        $__plnNotice = app(\Juwri\Modules\Pharmacy\Services\Pharmacy\PharmacyLicenceNotice::class)->forStore($__plnStore);
    } elseif (isset($vm['store']['public_id'])) {
        $__plnNotice = app(\Juwri\Modules\Pharmacy\Services\Pharmacy\PharmacyLicenceNotice::class)->forPublicId((string) $vm['store']['public_id']);
    }
@endphp
@if ($__plnNotice !== null)
<footer class="mb-pharmacy-licence" data-pharmacy-licence aria-label="{{ ___('Pharmacy licence') }}">
    <strong class="mb-pharmacy-licence__title">{{ ___('Licensed pharmacy') }}</strong>
    <dl class="mb-pharmacy-licence__list">
        <div><dt>{{ ___('Licence number') }}</dt><dd dir="auto">{{ $__plnNotice['licence_number'] }}</dd></div>
        @isset($__plnNotice['pharmacist'])
        <div><dt>{{ ___('Responsible pharmacist') }}</dt><dd dir="auto">{{ $__plnNotice['pharmacist'] }}</dd></div>
        @endisset
        @isset($__plnNotice['cr_number'])
        <div><dt>{{ ___('Commercial Registration No.') }}</dt><dd dir="auto">{{ $__plnNotice['cr_number'] }}</dd></div>
        @endisset
    </dl>
</footer>
<style @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
.mb-pharmacy-licence{box-sizing:border-box;padding-block:20px calc(88px + env(safe-area-inset-bottom,0px));padding-inline:16px;text-align:center;font:12.5px/1.5 var(--tf-body,inherit);color:var(--tc-text,inherit);opacity:.85}
.mb-pharmacy-licence__title{display:block;font-weight:700;margin-block-end:6px}
.mb-pharmacy-licence__list{margin:0;display:flex;flex-wrap:wrap;gap:4px 18px;justify-content:center}
.mb-pharmacy-licence__list div{display:flex;gap:6px;align-items:baseline}
.mb-pharmacy-licence__list dt{font-weight:600}
.mb-pharmacy-licence__list dt::after{content:":"}
.mb-pharmacy-licence__list dd{margin:0;unicode-bidi:plaintext}
</style>
@endif
