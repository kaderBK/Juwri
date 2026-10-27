{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- P1-23 part 6: a store payment (gift card, store-credit top-up, deposit) paid by bank transfer — the STORE's own bank
     details (never the platform's); the store owner confirms it once the money arrives. --}}
@extends('store_templates.layout')

@push('style_at_top')
    <style nonce="{{ csp_nonce() }}">
        .mb-bt{max-width:640px;margin-inline:auto;margin-block:32px;padding-inline:16px}
        .mb-bt-card{border:1px solid rgba(0,0,0,.12);border-radius:16px;padding:clamp(18px,4vw,28px);background:#fff;color:#1c1c1c}
        .mb-bt-title{font-size:1.35rem;font-weight:700;margin-block:0 6px}
        .mb-bt-sub{opacity:.7;margin-block:0 20px}
        .mb-bt-amount{display:flex;align-items:baseline;gap:.5ch;flex-wrap:wrap;font-size:1.05rem;margin-block:0 4px}
        .mb-bt-amount strong{font-size:1.6rem;font-weight:800}
        .mb-bt-ref{opacity:.75;margin-block:0 20px}
        .mb-bt-label{font-weight:600;margin-block:0 6px}
        .mb-bt-bank{white-space:pre-line;background:rgba(0,0,0,.035);border-radius:12px;padding:12px 14px;margin-block:0 20px;line-height:1.6;overflow-wrap:anywhere}
        .mb-bt-note{font-size:.9rem;opacity:.75;margin:0}
        html[dir="rtl"] .mb-bt-amount{text-align:start}
    </style>
@endpush

@section('content')
    @php
        $btTitle = trim((string) ($postOptions->restaurant_wire_transfer_title ?? '')) ?: ___('Pay by bank transfer');
    @endphp
    <div class="mb-bt">
        <div class="mb-bt-card" data-store-bank-transfer>
            <h1 class="mb-bt-title">{{ $btTitle }}</h1>
            <p class="mb-bt-sub">{{ $store->title }}</p>

            <p class="mb-bt-amount">
                <span>{{ ___('Amount to transfer') }}:</span>
                <strong><bdi>{{ price_code_format($transaction->amount) }}</bdi></strong>
            </p>
            <p class="mb-bt-ref">{{ $transaction->product_name }} &middot; {{ ___('Reference') }}: <bdi>#{{ $transaction->id }}</bdi></p>

            <p class="mb-bt-label">{{ ___('Bank account details') }}</p>
            <div class="mb-bt-bank">{{ $bankInfo }}</div>

            <p class="mb-bt-note">{{ ___('Send the amount to this account and quote the reference. The store confirms your payment once the money arrives.') }}</p>
        </div>
    </div>
@endsection
