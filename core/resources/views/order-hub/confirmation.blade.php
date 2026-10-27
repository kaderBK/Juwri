{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('order-hub.layout')
@section('title', ___('Order confirmation'))
@section('content')
    @php $okAll = \Juwri\Core\Support\ViewHelpers::allOk($rows); @endphp
    <div class="hub-card hub-empty" style="padding:32px 16px">
        <div class="e">{{ $okAll ? '🎉' : '⚠️' }}</div>
        <h2 style="margin:0 0 4px;color:var(--hub-ink)">{{ $okAll ? ___('Orders placed!') : ___('Order results') }}</h2>
        <p style="margin:0">{{ ___('You will place a separate order at each store. Pay at each store.') }}</p>
    </div>

    @foreach($rows as $r)
        <div class="hub-card">
            <div class="hub-line" style="border:0;padding:0">
                <div class="ph" style="background: {{ $r['ok'] ? '#dcfce7' : '#fee2e2' }}">{{ $r['ok'] ? '✓' : '✕' }}</div>
                <div>
                    <div style="font-weight:700">{{ $r['store'] }}</div>
                    @if($r['ok'])
                        <div class="q">{{ ___('Order') }} #{{ $r['order_id'] }}</div>
                    @else
                        <div class="q" style="color:#b91c1c">{{ $r['error'] }}</div>
                    @endif
                </div>
                @if($r['ok'] && $r['track'])
                    <a class="hub-chip on amt" href="{{ $r['track'] }}" style="min-height:40px">{{ ___('Track') }}</a>
                @endif
            </div>
        </div>
    @endforeach

    <a class="hub-cta secondary" href="{{ route('orderhub.index') }}">{{ ___('Back to stores') }}</a>
@endsection
