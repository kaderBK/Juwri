{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ is_rtl(app()->getLocale()) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ ___('Your cart') }} — {{ $store->title }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="font-family: sans-serif; max-width: 560px; margin: 2rem auto; padding: 0 1rem;">

@if(session('success'))
    <p style="color: green;">{{ session('success') }}</p>
@endif

<h1>{{ ___('Your cart') }}</h1>

@if($lines->isEmpty())
    <p>{{ ___('Your cart is empty.') }}</p>
@else
    <table style="width: 100%; border-collapse: collapse;" id="cart-lines">
        <thead>
        <tr>
            <th style="text-align: start;">{{ ___('Item') }}</th>
            <th style="text-align: start;">{{ ___('Quantity') }}</th>
            <th style="text-align: start;">{{ ___('Line total') }}</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        @foreach($lines as $line)
            <tr data-cart-line="{{ $line['line_id'] }}" data-variant-id="{{ $line['variant_id'] }}">
                <td>
                    {{ $line['name'] }}
                    @if($line['variant_title'])
                        <br><small>{{ $line['variant_title'] }}</small>
                    @endif
                </td>
                <td>
                    <form method="POST" action="{{ route('storefront.goods.cart.line.update', ['slug' => $store->slug, 'line' => $line['line_id']]) }}">
                        @csrf
                        <input type="number" name="quantity" value="{{ $line['quantity'] }}" min="0" max="99" style="width: 4rem;">
                        <button type="submit">{{ ___('Update') }}</button>
                    </form>
                </td>
                <td data-line-total>{{ store_price_symbol($line['line_total'], $store->id) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <p><strong>{{ ___('Total') }}:</strong> <span id="cart-total">{{ store_price_symbol($total, $store->id) }}</span></p>

    @if(Route::has('storefront.checkout.show'))
        <a href="{{ route('storefront.checkout.show', ['slug' => $store->slug]) }}">{{ ___('Checkout') }}</a>
    @endif
@endif

</body>
</html>
