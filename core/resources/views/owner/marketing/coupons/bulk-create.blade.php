{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Bulk Create Coupons'))
@section('page_title', ___('Bulk Create Coupons'))
@section('page_sub', ___('Generate a batch of unique discount codes for diners.'))

@section('page_actions')
    <x-ui.button :href="route('stores.coupons.index')" variant="secondary" icon="back">{{ ___('Back to Coupons') }}</x-ui.button>
@endsection

@section('content')
<div class="ui-scope ui-cpf" x-data="{ type: @js(old('coupon_type', 'percentage')) }">
    <x-plan-gate-notice feature="coupons" />

    @if ($errors->any())
        <x-ui.banner tone="danger">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-ui.banner>
    @endif

    <form action="{{ route('stores.coupons.bulk.confirm') }}" method="post" class="ui-cpf__form">
        @csrf
        <div class="ui-cpf__main">
            <x-ui.card :title="___('Generate Coupon Batch')">
                <div class="ui-cpf__grid">
                    <div class="ui-cpf__field">
                        <label class="ui-label" for="cpf-batch-name">{{ ___('Batch Name') }}</label>
                        <input type="text" id="cpf-batch-name" name="name" class="ui-input" maxlength="120" placeholder="{{ ___('Optional label for this batch') }}" value="{{ old('name') }}">
                        @error('name')<small class="ui-cpf__error">{{ $message }}</small>@enderror
                    </div>
                    <div class="ui-cpf__field">
                        <label class="ui-label" for="cpf-prefix">{{ ___('Code Prefix') }}</label>
                        <input type="text" id="cpf-prefix" name="prefix" class="ui-input ui-cpf__code" maxlength="12" dir="ltr" autocomplete="off" placeholder="{{ ___('Optional, e.g. SPRING') }}" value="{{ old('prefix') }}">
                        @error('prefix')<small class="ui-cpf__error">{{ $message }}</small>@enderror
                    </div>
                    <div class="ui-cpf__field">
                        <label class="ui-label" for="cpf-count">{{ ___('Quantity') }} *</label>
                        <input type="number" id="cpf-count" name="count" class="ui-input" min="1" max="1000" required value="{{ old('count', 10) }}">
                        @error('count')<small class="ui-cpf__error">{{ $message }}</small>@enderror
                    </div>
                    <div class="ui-cpf__field">
                        <label class="ui-label" for="cpf-length">{{ ___('Random Code Length') }}</label>
                        <input type="number" id="cpf-length" name="length" class="ui-input" min="6" max="12" value="{{ old('length', 8) }}">
                        @error('length')<small class="ui-cpf__error">{{ $message }}</small>@enderror
                    </div>
                </div>
            </x-ui.card>

            <x-ui.card :title="___('Discount')">
                <div class="ui-cpf__grid">
                    <div class="ui-cpf__field">
                        <label class="ui-label" for="cpf-type">{{ ___('Type') }}</label>
                        <select id="cpf-type" name="coupon_type" class="ui-input" x-model="type" required>
                            <option value="percentage">{{ ___('Percentage') }}</option>
                            <option value="fixed">{{ ___('Fixed amount') }}</option>
                        </select>
                    </div>
                    <div class="ui-cpf__field">
                        <label class="ui-label" for="cpf-value" x-text="type === 'percentage' ? @js(___('Percent off')) : @js(___('Amount off'))">{{ ___('Value') }}</label>
                        <input type="number" id="cpf-value" step="any" min="0" name="percentage" class="ui-input" inputmode="decimal" required value="{{ old('percentage', 10) }}">
                        @error('percentage')<small class="ui-cpf__error">{{ $message }}</small>@enderror
                    </div>
                </div>
            </x-ui.card>

            <x-ui.card :title="___('Limits')">
                <div class="ui-cpf__grid">
                    <div class="ui-cpf__field">
                        <label class="ui-label" for="cpf-limit">{{ ___('Max redemptions') }}</label>
                        <input type="number" id="cpf-limit" min="0" name="limit" class="ui-input" inputmode="numeric" placeholder="{{ ___('Unlimited') }}" value="{{ old('limit', 1) }}">
                        @error('limit')<small class="ui-cpf__error">{{ $message }}</small>@enderror
                    </div>
                    <div class="ui-cpf__field">
                        <label class="ui-label" for="cpf-per">{{ ___('Max per customer') }}</label>
                        <input type="number" id="cpf-per" min="1" name="per_customer_limit" class="ui-input" inputmode="numeric" placeholder="{{ ___('Unlimited') }}" value="{{ old('per_customer_limit') }}">
                        @error('per_customer_limit')<small class="ui-cpf__error">{{ $message }}</small>@enderror
                    </div>
                    <div class="ui-cpf__field">
                        <label class="ui-label" for="cpf-expiry">{{ ___('Expiry date') }}</label>
                        <input type="date" id="cpf-expiry" name="expiry_at" class="ui-input" value="{{ old('expiry_at') }}">
                        <small class="ui-cpf__hint">{{ ___('Leave blank for no expiry.') }}</small>
                        @error('expiry_at')<small class="ui-cpf__error">{{ $message }}</small>@enderror
                    </div>
                </div>
            </x-ui.card>
        </div>

        <aside class="ui-cpf__side">
            <div class="ui-cpf__actions">
                <x-ui.button :href="route('stores.coupons.index')" variant="secondary">{{ ___('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" icon="check" data-ui-busy>{{ ___('Review Batch') }}</x-ui.button>
            </div>
        </aside>
    </form>
</div>
@endsection
