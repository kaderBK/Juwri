{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.layout')

@push('style_at_top')
    <style nonce="{{ csp_nonce() }}">
        .mb-wt{max-width:640px;margin-inline:auto;margin-block:32px;padding-inline:16px}
        .mb-wt-card{border:1px solid rgba(0,0,0,.12);border-radius:16px;padding:clamp(18px,4vw,28px);background:#fff;color:#1c1c1c}
        .mb-wt-title{font-size:1.35rem;font-weight:700;margin-block:0 6px}
        .mb-wt-sub{opacity:.7;margin-block:0 20px}
        .mb-wt-amount{display:flex;align-items:baseline;gap:.5ch;flex-wrap:wrap;font-size:1.05rem;margin-block:0 4px}
        .mb-wt-amount strong{font-size:1.6rem;font-weight:800}
        .mb-wt-order{opacity:.75;margin-block:0 20px}
        .mb-wt-nums{list-style:none;margin:0 0 20px;padding:0;display:grid;gap:10px}
        .mb-wt-num{display:flex;justify-content:space-between;align-items:center;gap:12px;
            border:1px solid rgba(0,0,0,.12);border-radius:12px;padding:12px 14px}
        .mb-wt-num-label{font-weight:600}
        .mb-wt-num-value{font-variant-numeric:tabular-nums;font-weight:700;letter-spacing:.02em}
        .mb-wt-instructions{white-space:pre-line;background:rgba(0,0,0,.035);border-radius:12px;
            padding:12px 14px;margin-block:0 20px;line-height:1.6}
        .mb-wt-claim label{display:block;font-weight:600;margin-block:0 6px}
        .mb-wt-field{margin-block:0 16px}
        .mb-wt-input,.mb-wt-select{width:100%;box-sizing:border-box;padding:12px 14px;border-radius:10px;
            border:1px solid rgba(0,0,0,.25);font-size:1rem;background:#fff;color:inherit}
        .mb-wt-note{font-size:.85rem;opacity:.7;margin-block:6px 0}
        .mb-wt-submit{display:inline-block;width:100%;border:0;border-radius:12px;padding:14px 18px;
            font-size:1.05rem;font-weight:700;cursor:pointer;color:#fff;background:var(--theme-color-1,#111)}
        .mb-wt-alert{border-radius:12px;padding:12px 14px;margin-block:0 18px;
            background:#fdecea;color:#611a15;border:1px solid #f5c6cb}
        html[dir="rtl"] .mb-wt-num,html[dir="rtl"] .mb-wt-amount{text-align:start}
    </style>
@endpush

@section('content')
    @php
        $o = $postOptions;
        $wallets = [];
        if (!empty($o->restaurant_wallet_transfer_syriatel_number)) {
            $wallets['syriatel'] = ['label' => ___('Syriatel Cash'), 'number' => $o->restaurant_wallet_transfer_syriatel_number];
        }
        if (!empty($o->restaurant_wallet_transfer_mtn_number)) {
            $wallets['mtn'] = ['label' => ___('MTN Cash'), 'number' => $o->restaurant_wallet_transfer_mtn_number];
        }
        if (!empty($o->restaurant_wallet_transfer_shamcash_number)) {
            $wallets['shamcash'] = ['label' => ___('Sham Cash'), 'number' => $o->restaurant_wallet_transfer_shamcash_number];
        }
        $wtTitle = trim((string) ($o->restaurant_wallet_transfer_title ?? '')) ?: ___('Pay by wallet transfer');
        $wtInstructions = trim((string) ($o->restaurant_wallet_transfer_instructions ?? ''));
    @endphp

    <div class="mb-wt">
        <div class="mb-wt-card">
            <h1 class="mb-wt-title">{{ $wtTitle }}</h1>
            <p class="mb-wt-sub">{{ $store->title }}</p>

            @if(\Session::get('app_alert_message'))
                <div class="mb-wt-alert">{{ \Session::get('app_alert_message') }}</div>
            @endif

            <p class="mb-wt-amount">
                <span>{{ ___('Amount to transfer') }}:</span>
                <strong><bdi>{{ price_code_format($transaction->amount) }}</bdi></strong>
            </p>
            @if($isOrder ?? true)
                <p class="mb-wt-order">{{ ___('Order number') }}: <bdi>#{{ $transaction->product_id }}</bdi></p>
            @else
                {{-- P1-23 part 6: a gift card / top-up / deposit — its own reference, not an order --}}
                <p class="mb-wt-order">{{ $transaction->product_name }} &middot; {{ ___('Reference') }}: <bdi>#{{ $transaction->id }}</bdi></p>
            @endif

            @if(!empty($wallets))
                <ul class="mb-wt-nums">
                    @foreach($wallets as $wKey => $w)
                        <li class="mb-wt-num">
                            <span class="mb-wt-num-label">{{ $w['label'] }}</span>
                            <span class="mb-wt-num-value"><bdi>{{ $w['number'] }}</bdi></span>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if($wtInstructions !== '')
                <div class="mb-wt-instructions">{{ $wtInstructions }}</div>
            @endif

            <form class="mb-wt-claim" action="{{ $claimUrl ?? \Illuminate\Support\Facades\URL::signedRoute('payment.claim', ['transaction' => $transaction->id]) }}" method="POST">
                @csrf

                @if(count($wallets) > 1)
                    <div class="mb-wt-field">
                        <label for="mb-wt-wallet">{{ ___('Which wallet did you send from?') }}</label>
                        <select class="mb-wt-select" id="mb-wt-wallet" name="wallet" required>
                            @foreach($wallets as $wKey => $w)
                                <option value="{{ $wKey }}">{{ $w['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                @elseif(count($wallets) === 1)
                    <input type="hidden" name="wallet" value="{{ array_key_first($wallets) }}">
                @endif

                <div class="mb-wt-field">
                    <label for="mb-wt-txn">{{ ___('Transaction ID from your wallet app') }}</label>
                    <input class="mb-wt-input" id="mb-wt-txn" type="text" name="txn_id"
                           inputmode="latin" autocomplete="off"
                           placeholder="{{ ___('e.g.') }} 1234567890" required maxlength="120">
                    @if($isOrder ?? true)
                        <p class="mb-wt-note">{{ ___('Enter the reference number your wallet app showed after the transfer. The restaurant confirms it against their wallet SMS before your order is accepted.') }}</p>
                    @else
                        <p class="mb-wt-note">{{ ___('Enter the reference number your wallet app showed after the transfer. The store confirms it against their wallet SMS before your payment is accepted.') }}</p>
                    @endif
                </div>

                <button type="submit" class="mb-wt-submit">{{ ___('I have transferred — submit') }}</button>
            </form>
        </div>
    </div>
@endsection
