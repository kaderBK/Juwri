{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ in_array(app()->getLocale(), config('laravellocalization.rtlLocales', [])) ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $vocab['create_item'] ?? ___('Book a service') }} — {{ $store->title }}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="font-family: sans-serif; max-width: 640px; margin: 2rem auto; padding: 0 1rem;">

<h1>{{ $store->title }}</h1>

@if(session('success'))
    <p style="color: green;">{{ session('success') }}</p>
@endif
@if($errors->any())
    <ul style="color: #b00020;">
        @foreach($errors->all() as $error)
            <li>{{ $error }}</li>
        @endforeach
    </ul>
@endif

<h2>{{ $vocab['items'] ?? ___('Services') }}</h2>
@if($services->isEmpty())
    <p>{{ ___('Nothing here yet.') }}</p>
@else
    <ul style="list-style:none;padding:0;">
        @foreach($services as $service)
            @php $unit = $booking->unit($service); @endphp
            <li style="border:1px solid #ddd;border-radius:8px;padding:10px;margin-bottom:8px;">
                <strong>{{ $service->getRawOriginal('name') }}</strong>
                — {{ store_price_symbol($service->price, $store->id) }} {{ $unit === 'kg' ? ___('per kg') : ___('per item') }}

                <form method="POST" action="{{ route('laundry.book.addLine', ['slug' => $store->slug, 'menu' => $service->id]) }}" style="margin-top:6px;">
                    @csrf
                    <label for="qty-{{ $service->id }}">{{ $unit === 'kg' ? ___('Weight (kg)') : ___('Quantity') }}</label>
                    <input type="number" id="qty-{{ $service->id }}" name="quantity"
                           value="{{ $unit === 'kg' ? '1.0' : '1' }}"
                           min="{{ $unit === 'kg' ? '0.1' : '1' }}"
                           step="{{ $unit === 'kg' ? '0.1' : '1' }}">
                    <button type="submit">{{ $vocab['add_to_cart'] ?? ___('Book') }}</button>
                </form>
            </li>
        @endforeach
    </ul>
@endif

<h2>{{ $vocab['orders'] ?? ___('Bookings') }}</h2>
@if($lines->isEmpty())
    <p>{{ ___('Your cart is empty.') }}</p>
@else
    <ul>
        @foreach($lines as $line)
            <li>
                {{ $line['unit'] === 'kg' ? number_format($line['weight_kg'], 2).' kg' : $line['quantity'].'×' }}
                {{ $line['name'] }}
                @if($line['variant_title']) ({{ $line['variant_title'] }}) @endif
                — {{ store_price_symbol($line['line_total'], $store->id) }}
            </li>
        @endforeach
    </ul>
    <p><strong>{{ ___('Total') }}:</strong> {{ store_price_symbol($total, $store->id) }}</p>

    @if(empty($modes))
        <p>{{ ___('This store has no fulfillment methods enabled right now.') }}</p>
    @else
        <form method="POST" action="{{ route('laundry.book.place', ['slug' => $store->slug]) }}">
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
                <label for="address">{{ ___('Pickup / delivery address') }}</label>
                <input type="text" name="address" id="address" value="{{ old('address') }}">
            </div>

            @if($slotMode)
                <div>
                    <label for="scheduled_at">{{ ___('Pick a time') }}</label>
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

            <button type="submit">{{ $vocab['add_to_cart'] ?? ___('Book') }}</button>
        </form>
    @endif
@endif

@include('store_templates._partials.client-order-key')
</body>
</html>
