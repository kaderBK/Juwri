{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

{{-- P3-09 (basket §8–§10, API-25 / API-30 / API-31): pick one grocery order line by line. --}}
@php
    $exp = \Juwri\Core\Money\Currencies::exponent($currency);
    $fmt = fn (int $minor) => $currency.' '.number_format(\Juwri\Core\Money\Money::majorFromMinor($minor, $currency), $exp);
    $picking = $order->picking_status === 'picking';
    $picked = $order->picking_status === 'picked';
    $actions = [
        'offer_replacement' => ___('Offer a replacement'),
        'call_customer' => ___('Call the customer'),
        'remove_item' => ___('Remove it'),
    ];
    $states = [
        'picked' => ___('Picked'),
        'proposed' => ___('Waiting for the customer'),
        'replaced' => ___('Replaced'),
        'removed' => ___('Removed'),
    ];
    $grocery = app(\Juwri\Modules\Menu\Contracts\GroceryCatalog::class);
    $step = fn () => route('stores.picking.step', $order->id);
@endphp

@section('title', ___('Picking').' #'.$order->id)
@section('page_title', ___('Picking').' #'.$order->id)
@section('page_sub', ($order->name ?: ___('Guest')).($order->slot_starts_at ? ' · '.date_formating($order->slot_starts_at, 'd M, h:i A') : ''))

@section('page_actions')
    <x-ui.button :href="route('stores.orders.show', $order->id)" variant="secondary" icon="back">{{ ___('Order') }}</x-ui.button>
    @if($order->picking_status === null)
        <form method="post" action="{{ $step() }}">
            @csrf
            <input type="hidden" name="step" value="start">
            <x-ui.button type="submit" icon="check" data-ui-busy data-picking-start>{{ ___('Start picking') }}</x-ui.button>
        </form>
    @elseif($picking)
        <form method="post" action="{{ $step() }}" data-confirm="{{ ___('Finish picking and charge the final amount?') }}">
            @csrf
            <input type="hidden" name="step" value="finish">
            <x-ui.button type="submit" icon="check" data-ui-busy data-picking-finish>{{ ___('Finish picking') }}</x-ui.button>
        </form>
    @endif
@endsection

@section('content')
<div class="ui-scope ui-disp" data-picking="{{ $order->id }}" data-picking-status="{{ $order->picking_status ?? 'none' }}">
    @if($order->phone_number)
        <p class="ui-card"><span>{{ ___('Customer phone') }}:</span> <a class="ui-ltr" href="tel:{{ preg_replace('/[^0-9+]/', '', (string) $order->phone_number) }}">{{ $order->phone_number }}</a></p>
    @endif

    @foreach($lines as $line)
        @php
            $weighed = (int) $line->weight_g > 0;
            $settled = in_array($line->pick_status, ['replaced', 'removed'], true);
            $name = $line->menu->name ?? ___('Item');
        @endphp
        <article class="ui-card ui-disp__item" data-picking-line="{{ $line->id }}" data-pick-status="{{ $line->pick_status ?? 'open' }}">
            <header class="ui-disp__head">
                <div class="ui-disp__amount">
                    <strong><span class="ui-ltr">{{ (int) $line->quantity }}×</span> {{ $name }}</strong>
                    <span class="ui-disp__reason">
                        @if($weighed){{ ___('Sold by weight') }} · {{ $grocery->stepLabel((int) $line->weight_g * max(1, (int) $line->quantity)) }}@endif
                        @if($line->picker_action) · {{ ___('If out of stock') }}: {{ $actions[$line->picker_action] ?? $line->picker_action }}@endif
                        @if($line->picker_action === 'call_customer') ({{ ___('no answer') }}: {{ $actions[$line->picker_fallback ?: 'remove_item'] ?? '' }})@endif
                    </span>
                </div>
                <x-ui.pill :tone="$line->pick_status === 'removed' ? 'danger' : ($line->pick_status === 'proposed' ? 'warning' : ($line->pick_status ? 'success' : 'neutral'))">{{ $states[$line->pick_status] ?? ___('To pick') }}</x-ui.pill>
            </header>
            <dl class="ui-disp__facts">
                @if($line->cap_minor !== null)<div><dt>{{ ___('Up to') }}</dt><dd class="ui-ltr">{{ $fmt((int) $line->cap_minor) }}</dd></div>@endif
                @if($line->actual_g)<div><dt>{{ ___('Weighed') }}</dt><dd class="ui-ltr">{{ $grocery->stepLabel((int) $line->actual_g) }}</dd></div>@endif
                @if($line->final_minor !== null)<div><dt>{{ ___('Final') }}</dt><dd class="ui-ltr">{{ $fmt((int) $line->final_minor) }}</dd></div>@endif
                @if($line->replacement_name)<div><dt>{{ ___('Replacement') }}</dt><dd>{{ $line->replacement_name }} · <span class="ui-ltr">{{ $fmt((int) $line->replacement_minor) }}</span></dd></div>@endif
                @if($line->refunded_minor)<div><dt>{{ ___('Refunded') }}</dt><dd class="ui-ltr">{{ $fmt((int) $line->refunded_minor) }}</dd></div>@endif
            </dl>

            @if($picking && ! $settled)
                <div class="ui-disp__actions">
                    @if($weighed)
                        <form method="post" action="{{ $step() }}" class="ui-form-inline" data-picking-weigh>
                            @csrf
                            <input type="hidden" name="step" value="weigh"><input type="hidden" name="item" value="{{ $line->id }}">
                            <input class="ui-input ui-input--sm" type="number" name="grams" min="1" required placeholder="{{ ___('Grams') }}" aria-label="{{ ___('Weight in grams') }}">
                            <x-ui.button type="submit" size="sm" data-ui-busy>{{ ___('Save weight') }}</x-ui.button>
                        </form>
                    @elseif($line->pick_status === null)
                        <form method="post" action="{{ $step() }}">
                            @csrf
                            <input type="hidden" name="step" value="pick"><input type="hidden" name="item" value="{{ $line->id }}">
                            <x-ui.button type="submit" size="sm" icon="check" data-ui-busy data-picking-pick>{{ ___('Picked') }}</x-ui.button>
                        </form>
                    @endif
                    @if(in_array($line->picker_action, ['offer_replacement', 'call_customer'], true) || $line->picker_action === null)
                        <form method="post" action="{{ $step() }}" class="ui-form-inline" data-picking-propose>
                            @csrf
                            <input type="hidden" name="step" value="{{ $line->picker_action === 'call_customer' ? 'call' : 'propose' }}"><input type="hidden" name="item" value="{{ $line->id }}">
                            @if($line->picker_action === 'call_customer')
                                <select class="ui-input ui-input--sm" name="outcome" aria-label="{{ ___('What the customer said') }}">
                                    <option value="replace">{{ ___('Agreed a replacement') }}</option>
                                    <option value="remove">{{ ___('Remove it') }}</option>
                                    <option value="no_answer">{{ ___('No answer') }}</option>
                                </select>
                            @endif
                            <input class="ui-input ui-input--sm" name="name" maxlength="190" placeholder="{{ ___('Replacement') }}" aria-label="{{ ___('Replacement') }}">
                            <input class="ui-input ui-input--sm" type="number" step="any" min="0" name="price" placeholder="{{ ___('Price') }}" aria-label="{{ ___('Price') }}">
                            <x-ui.button type="submit" size="sm" variant="secondary" data-ui-busy>{{ $line->picker_action === 'call_customer' ? ___('Save call') : ___('Offer replacement') }}</x-ui.button>
                        </form>
                    @endif
                    <form method="post" action="{{ $step() }}" data-confirm="{{ ___('Remove this item from the order?') }}" data-confirm-danger>
                        @csrf
                        <input type="hidden" name="step" value="remove"><input type="hidden" name="item" value="{{ $line->id }}">
                        <x-ui.button type="submit" size="sm" variant="secondary" data-ui-busy data-picking-remove>{{ ___('Remove') }}</x-ui.button>
                    </form>
                </div>
            @elseif($picked && $line->pick_status !== 'removed')
                <div class="ui-disp__actions">
                    <form method="post" action="{{ $step() }}" data-confirm="{{ ___('Remove this item and refund it?') }}" data-confirm-danger>
                        @csrf
                        <input type="hidden" name="step" value="remove_after"><input type="hidden" name="item" value="{{ $line->id }}">
                        <x-ui.button type="submit" size="sm" variant="secondary" data-ui-busy data-picking-remove-after>{{ ___('Remove and refund') }}</x-ui.button>
                    </form>
                </div>
            @endif
        </article>
    @endforeach

    <section class="ui-card">
        <dl class="ui-od__totals">
            <div class="ui-od__grand"><dt>{{ $picked ? ___('Total') : ___('Estimated total') }}</dt><dd class="ui-ltr">{{ $fmt(\Juwri\Core\Money\Money::minorFromMajor((float) $money['grand'], $currency)) }}</dd></div>
            @if($order->hold_minor)<div><dt>{{ ___('Card hold') }}</dt><dd class="ui-ltr">{{ $fmt((int) $order->hold_minor) }}</dd></div>@endif
            @if($order->captured_minor)<div><dt>{{ ___('Charged') }}</dt><dd class="ui-ltr">{{ $fmt((int) $order->captured_minor) }}</dd></div>@endif
        </dl>
    </section>
</div>
@endsection
