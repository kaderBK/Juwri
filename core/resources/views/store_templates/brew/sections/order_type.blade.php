{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    $__ctx = $vm['context'];
    $__types = $__ctx['order_types'];
    $__kiosk = ! empty($__ctx['kiosk']['on']);
    if ($__kiosk && ! empty($__ctx['kiosk']['order_types'])) {
        $__types = \Juwri\Core\Support\ViewHelpers::kioskOrderTypes($__types, $__ctx['kiosk']['order_types']);
    }
    // the cafe flow: pickup, then dine in, then delivery (the view model's own order of the offered types is kept inside each group)
    $__types = \Juwri\Core\Support\ViewHelpers::cafeOrderTypes($__types);
    $__sel = $__ctx['selected_order_type'] ?? '';
    $__icons = ['delivery' => 'bike', 'pickup' => 'shopping-bag', 'takeaway' => 'shopping-bag', 'dine_in' => 'armchair', 'pickup_slot' => 'shopping-bag', 'room_service' => 'armchair'];
    $__branch = $vm['branch']['current']['name'] ?? $vm['store']['name'];
@endphp
<section class="c-ordertype" data-section="order_type">
    @if (count($__types) > 1)
    <div class="c-seg" role="radiogroup" aria-label="{{ $kit->s('Order type') }}">
        @foreach ($__types as $__t)
        <label class="c-seg__opt"><input class="c-seg__input u-vh" type="radio" name="order_type" value="{{ $__t['key'] }}" data-kit-order-type @checked($__t['key'] === $__sel)><span class="c-seg__face">{{ $kit->icon($__icons[$__t['key']] ?? 'shopping-bag') }}<span>{{ $__t['label'] }}</span></span></label>
        @endforeach
    </div>
    @endif
    @if (! $__kiosk)
    @if ($__sel === 'delivery')
    <p class="c-ctxrow c-ctxrow--static">{{ $kit->icon('map-pin') }}<span class="c-ctxrow__text">@if (! empty($__ctx['delivery_address']))<span class="c-ctxrow__cap">{{ $kit->s('Deliver to') }}</span><span class="c-ctxrow__val">{{ $__ctx['delivery_address']['label'] }}</span>@else<span class="c-ctxrow__val">{{ $kit->s('Deliver to') }}</span>@endif</span></p>
    @elseif ($__sel === 'dine_in')
    <p class="c-ctxrow c-ctxrow--static">{{ $kit->icon('armchair') }}<span class="c-ctxrow__text"><span class="c-ctxrow__val">{{ ! empty($__ctx['table']) ? $kit->s("We'll bring it to :table", ['table' => $__ctx['table']['label']]) : $kit->s('Scan the QR on your table') }}</span></span></p>
    @elseif (in_array($__sel, ['pickup', 'takeaway'], true) && empty($withHero))
    <p class="c-ctxrow c-ctxrow--static">{{ $kit->icon('shopping-bag') }}<span class="c-ctxrow__text"><span class="c-ctxrow__val">{{ $kit->s('Pickup from :branch', ['branch' => $__branch]) }}</span></span></p>
    @endif
    @endif
</section>
