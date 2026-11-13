{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- P6-172: 80 mm thermal receipt PDF rendered by TCPDF. Variables come pre-formatted
     from OrderReceiptPdf; this template NEVER computes, sums, rounds or number_formats.
     Layout is table + inline CSS only (no JS, no external assets, no flex/grid). --}}
@php
    // Show VAT columns when ANY line carries a rate or a tax value. This is a
    // presence check on the supplied strings, not a number computation.
    $__hasVat = false;
    foreach ($lines as $__l) {
        if (! empty($__l['rate']) || ! empty($__l['tax'])) {
            $__hasVat = true;
            break;
        }
    }
    // Direction-driven alignment helpers (text-align:start-equivalent via $rtl).
    $__start = $rtl ? 'right' : 'left';
    $__end = $rtl ? 'left' : 'right';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $title }} #{{ $order->id }}</title>
<style nonce="{{ csp_nonce() }}">
    html, body {
        margin: 0;
        padding: 0;
        font-family: 'dejavusans', sans-serif;
        font-size: 9pt;
        line-height: 1.25;
        color: #000;
    }
    .c { text-align: center; }
    .s { text-align: {{ $__start }}; }
    .e { text-align: {{ $__end }}; }
    .b { font-weight: bold; }
    .big { font-size: 12pt; }
    .small { font-size: 8pt; }
    hr.rule {
        border: 0;
        border-top: 1px dashed #000;
        margin: 1mm 0;
    }
    table {
        width: 100%;
        border-collapse: collapse;
    }
    th, td {
        padding: 0.6mm 1mm;
        vertical-align: top;
        word-wrap: break-word;
    }
    th { font-weight: bold; font-size: 7pt; text-align: {{ $__start }}; }
    td.num, th.num { text-align: {{ $__end }}; white-space: nowrap; }
    td.num { font-size: 8pt; }
    .totals td { padding: 0.8mm 1mm; }
    .totals tr.total td { font-weight: bold; border-top: 1px solid #000; }
    .img-wrap { text-align: center; margin-top: 2mm; }
    .img-wrap img { width: 30mm; height: 30mm; display: inline-block; }
</style>
</head>
<body>
<div class="c b big">{{ $store->title }}</div>
<div class="c b">{{ $title }}</div>
<div class="c small">#{{ $order->id }} &middot; {{ $issuedAt }}</div>
@if ($ctx->sellerName)
    <div class="c small">{{ $ctx->sellerName }}</div>
@endif
@if ($showVat && $ctx->vatNumber)
    <div class="c small">{{ ___('VAT number') }}: {{ $ctx->vatNumber }}</div>
@endif
@if ($showCr && $ctx->crNumber)
    <div class="c small">{{ ___('CR number') }}: {{ $ctx->crNumber }}</div>
@endif

<hr class="rule">
{{-- TCPDF ignores white-space:nowrap, so columns get explicit widths and numbers never share a narrow cell: with VAT
     columns the quantity and unit price sit on a second line under the item name. --}}
<table>
    <thead>
        <tr>
            @if ($__hasVat)
                <th width="30%">{{ ___('Item') }}</th>
                <th class="num" width="14%">{{ ___('VAT rate') }}</th>
                <th class="num" width="26%">{{ ___('VAT amount') }}</th>
                <th class="num" width="30%">{{ ___('Amount') }}</th>
            @else
                <th width="40%">{{ ___('Item') }}</th>
                <th class="num" width="10%">{{ ___('Qty') }}</th>
                <th class="num" width="24%">{{ ___('Unit price') }}</th>
                <th class="num" width="26%">{{ ___('Amount') }}</th>
            @endif
        </tr>
    </thead>
    <tbody>
        @foreach ($lines as $line)
            <tr>
                @if ($__hasVat)
                    <td width="30%">{{ $line['name'] }}<br><span class="small">{{ $line['qty'] }} &times;<br>{{ $line['unit'] }}</span></td>
                    <td class="num" width="14%">{{ $line['rate'] }}</td>
                    <td class="num" width="26%">{{ $line['tax'] }}</td>
                    <td class="num" width="30%">{{ $line['amount'] }}</td>
                @else
                    <td width="40%">{{ $line['name'] }}</td>
                    <td class="num" width="10%">{{ $line['qty'] }}</td>
                    <td class="num" width="24%">{{ $line['unit'] }}</td>
                    <td class="num" width="26%">{{ $line['amount'] }}</td>
                @endif
            </tr>
        @endforeach
    </tbody>
</table>

<hr class="rule">
<table class="totals">
    <tbody>
        @if ($discount !== null)
            <tr>
                <td width="35%">{{ ___('Discount') }}</td>
                <td class="num" width="65%">{{ $discount }}</td>
            </tr>
        @endif
        <tr>
            <td width="35%">{{ ___('VAT') }}</td>
            <td class="num" width="65%">{{ $vatTotal }}</td>
        </tr>
        <tr class="total">
            <td width="35%">{{ ___('Total') }}</td>
            <td class="num" width="65%">{{ $total }} {{ $currency }}</td>
        </tr>
    </tbody>
</table>

@if ($qrPng)
    <div class="img-wrap"><img src="{{ $qrPng }}" alt=""></div>
@endif
</body>
</html>