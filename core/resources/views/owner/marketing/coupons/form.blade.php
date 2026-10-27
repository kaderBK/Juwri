{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', isset($coupon) ? ___('Edit coupon') : ___('New coupon'))
@section('page_title', isset($coupon) ? ___('Edit coupon') : ___('New coupon'))
@section('page_sub', ___('Configure code, discount type, usage limit, and expiry.'))

@section('page_actions')
    <x-ui.button :href="route('stores.coupons.index')" variant="secondary" icon="back">{{ ___('Back') }}</x-ui.button>
@endsection

@section('content')
@php
    $isEdit = isset($coupon);
    $action = $isEdit ? route('stores.coupons.update', $coupon) : route('stores.coupons.store');
    $defaultType = $isEdit ? $coupon->coupon_type : 'percentage';
@endphp

<div class="ui-scope ui-cpf" x-data="{ type: @js(old('coupon_type', $defaultType)) }">
    <form action="{{ $action }}" method="post" class="ui-cpf__form">
        @csrf
        @if ($isEdit) @method('PUT') @endif

        <div class="ui-cpf__main">
            <x-ui.card :title="___('Code')">
                <div class="ui-cpf__field">
                    <label class="ui-label" for="cpf-code">{{ ___('Coupon code') }}</label>
                    <input type="text" id="cpf-code" name="code" class="ui-input ui-cpf__code" required maxlength="20" placeholder="WELCOME10" dir="ltr" autocomplete="off" value="{{ old('code', $isEdit ? $coupon->code : '') }}">
                    <small class="ui-cpf__hint">{{ ___('Stored uppercase. Diners enter at checkout.') }}</small>
                    @error('code')<small class="ui-cpf__error">{{ $message }}</small>@enderror
                </div>
            </x-ui.card>

            <x-ui.card :title="___('Discount')">
                <div class="ui-cpf__grid">
                    <div class="ui-cpf__field">
                        <label class="ui-label" for="cpf-type">{{ ___('Type') }}</label>
                        <select id="cpf-type" name="coupon_type" class="ui-input" x-model="type" required>
                            <option value="percentage">{{ ___('Percentage') }}</option>
                            <option value="fixed">{{ ___('Fixed amount') }}</option>
                            <option value="bogo">{{ ___('Buy X, get Y') }}</option>
                        </select>
                    </div>
                    <div class="ui-cpf__field" x-show="type !== 'bogo'">
                        <label class="ui-label" for="cpf-value" x-text="type === 'percentage' ? @js(___('Percent off')) : @js(___('Amount off'))">{{ ___('Value') }}</label>
                        <input type="number" id="cpf-value" step="any" min="0" name="percentage" class="ui-input" inputmode="decimal" :required="type !== 'bogo'" value="{{ old('percentage', $isEdit ? $coupon->percentage : '') }}">
                        @error('percentage')<small class="ui-cpf__error">{{ $message }}</small>@enderror
                    </div>
                </div>
            </x-ui.card>

            {{-- E5: buy-X-get-Y parameters. Shown only for the BOGO type; the item /
                 category IDs come from the menu. --}}
            <x-ui.card :title="___('Buy X, get Y')" x-show="type === 'bogo'" x-cloak>
                <div class="ui-cpf__grid ui-cpf__grid--3">
                    <div class="ui-cpf__field">
                        <label class="ui-label" for="cpf-buy-scope">{{ ___('Buy scope') }}</label>
                        <select id="cpf-buy-scope" name="buy_scope" class="ui-input" :required="type === 'bogo'">
                            <option value="item" @selected(old('buy_scope', $isEdit ? $coupon->buy_scope : 'item') === 'item')>{{ ___('Specific item') }}</option>
                            <option value="category" @selected(old('buy_scope', $isEdit ? $coupon->buy_scope : '') === 'category')>{{ ___('Any item in a category') }}</option>
                        </select>
                    </div>
                    <div class="ui-cpf__field">
                        <label class="ui-label" for="cpf-buy-id">{{ ___('Buy item / category ID') }}</label>
                        <input type="number" id="cpf-buy-id" min="1" name="buy_id" class="ui-input" inputmode="numeric" :required="type === 'bogo'" value="{{ old('buy_id', $isEdit ? $coupon->buy_id : '') }}">
                        @error('buy_id')<small class="ui-cpf__error">{{ $message }}</small>@enderror
                    </div>
                    <div class="ui-cpf__field">
                        <label class="ui-label" for="cpf-buy-qty">{{ ___('Buy quantity') }}</label>
                        <input type="number" id="cpf-buy-qty" min="1" name="buy_qty" class="ui-input" inputmode="numeric" :required="type === 'bogo'" value="{{ old('buy_qty', $isEdit ? $coupon->buy_qty : 1) }}">
                        @error('buy_qty')<small class="ui-cpf__error">{{ $message }}</small>@enderror
                    </div>
                    <div class="ui-cpf__field">
                        <label class="ui-label" for="cpf-get-id">{{ ___('Get item ID') }}</label>
                        <input type="number" id="cpf-get-id" min="1" name="get_id" class="ui-input" inputmode="numeric" :required="type === 'bogo'" value="{{ old('get_id', $isEdit ? $coupon->get_id : '') }}">
                        @error('get_id')<small class="ui-cpf__error">{{ $message }}</small>@enderror
                    </div>
                    <div class="ui-cpf__field">
                        <label class="ui-label" for="cpf-get-qty">{{ ___('Get quantity') }}</label>
                        <input type="number" id="cpf-get-qty" min="1" name="get_qty" class="ui-input" inputmode="numeric" :required="type === 'bogo'" value="{{ old('get_qty', $isEdit ? $coupon->get_qty : 1) }}">
                        @error('get_qty')<small class="ui-cpf__error">{{ $message }}</small>@enderror
                    </div>
                    <div class="ui-cpf__field">
                        <label class="ui-label" for="cpf-get-pct">{{ ___('Get discount %') }}</label>
                        <input type="number" id="cpf-get-pct" min="0" max="100" step="0.01" name="get_discount_percent" class="ui-input" inputmode="decimal" :required="type === 'bogo'" value="{{ old('get_discount_percent', $isEdit ? $coupon->get_discount_percent : 100) }}">
                        @error('get_discount_percent')<small class="ui-cpf__error">{{ $message }}</small>@enderror
                    </div>
                </div>
                <small class="ui-cpf__hint ui-cpf__hint--block">{{ ___('100% = the get item is free. IDs are menu item (or category) IDs.') }}</small>
            </x-ui.card>

            <x-ui.card :title="___('Limits')">
                <div class="ui-cpf__grid">
                    <div class="ui-cpf__field">
                        <label class="ui-label" for="cpf-limit">{{ ___('Max redemptions') }}</label>
                        <input type="number" id="cpf-limit" min="1" name="limit" class="ui-input" inputmode="numeric" placeholder="{{ ___('Unlimited') }}" value="{{ old('limit', $isEdit && (int) $coupon->limit > 0 ? $coupon->limit : '') }}">
                        @error('limit')<small class="ui-cpf__error">{{ $message }}</small>@enderror
                    </div>
                    <div class="ui-cpf__field">
                        <label class="ui-label" for="cpf-per">{{ ___('Max per customer') }}</label>
                        <input type="number" id="cpf-per" min="1" name="per_customer_limit" class="ui-input" inputmode="numeric" placeholder="{{ ___('Unlimited') }}" value="{{ old('per_customer_limit', $isEdit ? $coupon->per_customer_limit : '') }}">
                        <small class="ui-cpf__hint">{{ ___('How many times ONE diner may use this code. Leave blank for no per-diner cap.') }}</small>
                        @error('per_customer_limit')<small class="ui-cpf__error">{{ $message }}</small>@enderror
                    </div>
                    <div class="ui-cpf__field">
                        <label class="ui-label" for="cpf-expiry">{{ ___('Expiry date') }}</label>
                        <input type="date" id="cpf-expiry" name="expiry_at" class="ui-input" value="{{ old('expiry_at', $isEdit && isset($expiryDate) ? $expiryDate : '') }}">
                        <small class="ui-cpf__hint">{{ ___('Leave blank for no expiry.') }}</small>
                        @error('expiry_at')<small class="ui-cpf__error">{{ $message }}</small>@enderror
                    </div>
                </div>
            </x-ui.card>
        </div>

        <aside class="ui-cpf__side">
            @isset($redemptions)
                <section class="ui-card ui-card--flush">
                    <div class="ui-card__head">
                        <h2 class="ui-card__title">{{ ___('Coupon redemptions') }}</h2>
                        <div class="ui-card__actions"><x-ui.tag><span class="ui-ltr">{{ $coupon->used }} / {{ (int) $coupon->limit > 0 ? $coupon->limit : ___('Unlimited') }}</span></x-ui.tag></div>
                    </div>
                    @if (collect($redemptions)->isEmpty())
                        <x-ui.empty icon="gift" :title="___('No redemptions yet.')" />
                    @else
                        <ul class="ui-cpf__redeems">
                            @foreach ($redemptions as $r)
                                <li>
                                    <span class="ui-cpf__redeem-who">{{ optional($redeemers[$r->customer_id] ?? null)->name ?: ___('Guest') }} <small class="ui-ltr">#{{ $r->seq }}</small></span>
                                    <small class="ui-cpf__redeem-when">{{ date_formating($r->created_at, 'd M, Y') }}</small>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endisset

            <div class="ui-cpf__actions">
                <x-ui.button :href="route('stores.coupons.index')" variant="secondary">{{ ___('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" icon="check" data-ui-busy>{{ $isEdit ? ___('Save changes') : ___('Create coupon') }}</x-ui.button>
            </div>
        </aside>
    </form>
</div>
@endsection
