{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ is_rtl(app()->getLocale()) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $menu->getRawOriginal('name') }} — {{ $store->title }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="font-family: sans-serif; max-width: 480px; margin: 2rem auto; padding: 0 1rem;">

@if(session('success'))
    <p style="color: green;">{{ session('success') }}</p>
@endif
@if($errors->any())
    <p style="color: #b00020;">{{ $errors->first() }}</p>
@endif

<h1>{{ $menu->getRawOriginal('name') }}</h1>
<p>{{ store_price_symbol($menu->price, $store->id) }}</p>

<form method="POST" action="{{ route('storefront.goods.cart.add', ['slug' => $store->slug, 'menu' => $menu->id]) }}">
    @csrf

    @if($variants->isNotEmpty())
        <label for="variation">{{ ___('Choose an option') }}</label>
        <select name="variation" id="variation">
            @foreach($variants as $variant)
                <option value="{{ $variant->id }}">{{ store_price_symbol($variant->price, $store->id) }}</option>
            @endforeach
        </select>
    @endif

    <label for="quantity">{{ ___('Quantity') }}</label>
    <input type="number" name="quantity" id="quantity" value="1" min="1" max="99">

    <button type="submit">{{ ___('Add to cart') }}</button>
</form>

<p><a href="{{ route('storefront.goods.cart.show', ['slug' => $store->slug]) }}">{{ ___('View cart') }}</a>
    ({{ $lines->count() }})</p>

</body>
</html>
