{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Automations'))
@section('page_title', ___('Automations'))
@section('page_sub', ___('Automated cart recovery, win-back, and birthday offers.'))

@section('page_actions')
    <form method="get" action="{{ route('stores.automations.index') }}">
        <select name="store_id" class="ui-input ui-input--sm ui-mods__store" data-ui-do="submit" aria-label="{{ ___('Store') }}">
            @foreach ($stores as $r)
                <option value="{{ $r->id }}" @selected($rid == $r->id)>{{ $r->title }}</option>
            @endforeach
        </select>
    </form>
@endsection

@section('content')

@php
    // Same posting contract as app_switch(): a hidden 0 then a checkbox 1 under one name.
@endphp

<div class="ui-scope ui-settings ui-auto">

@if(session('success'))
    <x-ui.banner tone="success">{{ session('success') }}</x-ui.banner>
@endif

<form method="post" action="{{ route('stores.automations.update') }}?store_id={{ $rid }}" class="ui-auto__form">
    @csrf

    <x-ui.card :title="___('Cart recovery')">
        <x-slot:actions><span class="ui-auto__icon"><x-ui.icon name="orders" size="20" /></span></x-slot:actions>
        <p class="ui-auto__lead">{{ ___('Nudge customers who left an unpaid order. Email/SMS only reach customers who consented.') }}</p>
        <div class="ui-auto__switches">
            @foreach (['customer_cart_whatsapp' => ___('WhatsApp reminder'), 'customer_cart_email' => ___('Email reminder'), 'customer_cart_sms' => ___('SMS reminder')] as $__name => $__label)
                <div class="ui-auto__switch">
                    <input type="hidden" name="{{ $__name }}" value="0">
                    <x-ui.switch :name="$__name" :id="$__name" :checked="\Juwri\Core\Support\Views\OwnerOpsViewHelpers::automationSwitch($opts, $__name)" :label="$__label" />
                </div>
            @endforeach
        </div>
    </x-ui.card>

    <x-ui.card :title="___('Win-back')">
        <x-slot:actions><span class="ui-auto__icon"><x-ui.icon name="refresh" size="20" /></span></x-slot:actions>
        <p class="ui-auto__lead">{{ ___('Automatically coupon customers who have not ordered in a while.') }}</p>
        <div class="ui-auto__switches">
            <div class="ui-auto__switch">
                <input type="hidden" name="winback_enabled" value="0">
                <x-ui.switch name="winback_enabled" id="winback_enabled" :checked="\Juwri\Core\Support\Views\OwnerOpsViewHelpers::automationSwitch($opts, 'winback_enabled')" :label="___('Enable win-back')" />
            </div>
        </div>
        <div class="ui-auto__grid">
            <x-ui.field type="number" name="winback_after_days" :label="___('Lapsed after (days)')" min="1" :value="\Juwri\Core\Support\Views\OwnerOpsViewHelpers::opt($opts, 'winback_after_days', 30)" inputmode="numeric" />
            <x-ui.field type="number" name="winback_frequency_days" :label="___('Repeat no more than every (days)')" min="1" :value="\Juwri\Core\Support\Views\OwnerOpsViewHelpers::opt($opts, 'winback_frequency_days', 90)" inputmode="numeric" />
            <x-ui.field type="number" name="winback_reward_percentage" :label="___('Discount %')" min="1" max="100" :value="\Juwri\Core\Support\Views\OwnerOpsViewHelpers::opt($opts, 'winback_reward_percentage', 15)" inputmode="numeric" />
            <x-ui.field type="number" name="winback_validity_days" :label="___('Coupon validity (days)')" min="1" :value="\Juwri\Core\Support\Views\OwnerOpsViewHelpers::opt($opts, 'winback_validity_days', 14)" inputmode="numeric" />
        </div>
    </x-ui.card>

    <x-ui.card :title="___('Birthday offers')">
        <x-slot:actions><span class="ui-auto__icon"><x-ui.icon name="gift" size="20" /></span></x-slot:actions>
        <div class="ui-auto__switches">
            <div class="ui-auto__switch">
                <input type="hidden" name="birthday_offers_enabled" value="0">
                <x-ui.switch name="birthday_offers_enabled" id="birthday_offers_enabled" :checked="\Juwri\Core\Support\Views\OwnerOpsViewHelpers::automationSwitch($opts, 'birthday_offers_enabled')" :label="___('Enable birthday offers')" />
            </div>
        </div>
        <div class="ui-auto__grid">
            <x-ui.field type="number" name="birthday_reward_percentage" :label="___('Discount %')" min="1" max="100" :value="\Juwri\Core\Support\Views\OwnerOpsViewHelpers::opt($opts, 'birthday_reward_percentage', 10)" inputmode="numeric" />
            <x-ui.field type="number" name="birthday_validity_days" :label="___('Coupon validity (days)')" min="1" :value="\Juwri\Core\Support\Views\OwnerOpsViewHelpers::opt($opts, 'birthday_validity_days', 7)" inputmode="numeric" />
        </div>
    </x-ui.card>

    <div class="sticky-savebar" data-bridge-hide="form">
        <x-ui.button type="submit" icon="check" data-ui-busy>{{ ___('Save changes') }}</x-ui.button>
    </div>
</form>
</div>

@endsection
