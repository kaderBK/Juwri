{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ is_rtl(app()->getLocale()) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ ___('Checkout') }} — {{ $store->title }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="font-family: sans-serif; max-width: 560px; margin: 2rem auto; padding: 0 1rem;">

<h1>{{ ___('Checkout') }}</h1>

@if($errors->any())
    <ul style="color: #b00020;">
        @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
        @endforeach
    </ul>
@endif

@if($lines->isEmpty())
    <p>{{ ___('Your cart is empty.') }}</p>
@elseif(empty($modes))
    <p>{{ ___('This store has no fulfillment methods enabled right now.') }}</p>
@else
    <ul>
        @foreach($lines as $line)
            <li>{{ $line['quantity'] }}× {{ $line['name'] }}
                @if($line['variant_title']) ({{ $line['variant_title'] }}) @endif
                — {{ store_price_symbol($line['line_total'], $store->id) }}</li>
        @endforeach
    </ul>
    <p><strong>{{ ___('Total') }}:</strong> {{ store_price_symbol($total, $store->id) }}</p>

    <form method="POST" action="{{ route('storefront.checkout.place', ['slug' => $store->slug]) }}">
        @csrf

        <label for="customer_name">{{ ___('Name') }}</label>
        <input type="text" name="customer_name" id="customer_name" value="{{ old('customer_name') }}">

        <label for="phone_number">{{ ___('Phone') }}</label>
        <input type="text" name="phone_number" id="phone_number" value="{{ old('phone_number') }}">

        <fieldset>
            <legend>{{ ___('How would you like to get your order?') }}</legend>
            @foreach($modes as $mode)
                <label style="display:block;">
                    <input type="radio" name="type" value="{{ $mode }}" @checked(old('type', $modes[0] ?? null) === $mode) required>
                    {{ $registry->label($mode) }}
                </label>
            @endforeach
        </fieldset>

        <div>
            <label for="address">{{ ___('Address') }} ({{ ___('required for shipping') }})</label>
            <input type="text" name="address" id="address" value="{{ old('address') }}">
        </div>

        @if($shippingMethods->isNotEmpty())
            {{-- P3-08: shipping method + the address fields its zone is matched on --}}
            <div>
                <label for="city">{{ ___('City') }}</label>
                <input type="text" name="city" id="city" value="{{ old('city') }}">
                <label for="postal_code">{{ ___('Postal code') }}</label>
                <input type="text" name="postal_code" id="postal_code" value="{{ old('postal_code') }}">
                <label for="country">{{ ___('Country') }}</label>
                <input type="text" name="country" id="country" maxlength="2" value="{{ old('country') }}" placeholder="SA">
            </div>
            <fieldset>
                <legend>{{ ___('Shipping method') }}</legend>
                @foreach($shippingMethods as $method)
                    <label style="display:block;">
                        <input type="radio" name="shipping_method" value="{{ $method->public_id }}" @checked(old('shipping_method') === $method->public_id)>
                        {{ $method->name }}
                        @if($method->carrier) · {{ $method->carrier }} @endif
                        — {{ $method->fee_minor > 0 ? store_price_symbol(\Juwri\Core\Money\Money::majorFromMinor((int) $method->fee_minor, (string) $method->currency_code), $store->id) : ___('Free') }}
                    </label>
                @endforeach
            </fieldset>
        @endif

        @if($onlinePayment)
            {{-- P3-08 (F3): pay online on the store's own payment page, or later (on delivery / at collection) --}}
            <fieldset>
                <legend>{{ ___('Payment') }}</legend>
                <label style="display:block;"><input type="radio" name="payment_mode" value="pay_later" @checked(old('payment_mode', 'pay_later') === 'pay_later')> {{ ___('Pay on delivery or collection') }}</label>
                <label style="display:block;"><input type="radio" name="payment_mode" value="hosted" @checked(old('payment_mode') === 'hosted')> {{ ___('Pay online now') }}</label>
            </fieldset>
        @endif

        @if($slotMode)
            <div>
                <label for="scheduled_at">{{ ___('Pickup time') }}</label>
                <select name="scheduled_at" id="scheduled_at">
                    <option value="">{{ ___('Select a time') }}</option>
                    @foreach($slots as $slot)
                        <option value="{{ $slot['at']->format('Y-m-d H:i') }}" @disabled($slot['full'])>
                            {{ $slot['label'] }}{{ $slot['full'] ? ' ('.___('full').')' : '' }}
                        </option>
                    @endforeach
                </select>
            </div>
        @endif

        <button type="submit">{{ ___('Place order') }}</button>
    </form>
@endif

@include('store_templates._partials.client-order-key')
</body>
</html>
