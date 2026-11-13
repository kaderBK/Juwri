{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Audiences'))
@section('page_title', ___('Audiences & attribution'))
@section('page_sub', ___('Build segments and measure campaign revenue.'))

@section('page_actions')
    <form method="get" action="{{ route('crm.audiences') }}">
        <select name="store_id" class="ui-input ui-input--sm ui-mods__store" data-ui-do="submit" aria-label="{{ ___('Store') }}">
            @foreach ($stores as $r)
                <option value="{{ $r->id }}" @selected($rid == $r->id)>{{ $r->title }}</option>
            @endforeach
        </select>
    </form>
@endsection

@section('content')

<div class="ui-scope ui-aud">

@if(session('success'))<x-ui.banner tone="success">{{ session('success') }}</x-ui.banner>@endif
@if(isset($errors) && $errors->any())<x-ui.banner tone="danger">{{ $errors->first() }}</x-ui.banner>@endif

<div class="ui-aud__grid">
    <div class="ui-aud__main">
        <section class="ui-card ui-card--flush">
            <div class="ui-card__head">
                <h2 class="ui-card__title">{{ ___('Saved audiences') }}</h2>
                @if ($audiences->isNotEmpty())<div class="ui-card__actions"><x-ui.tag>{{ $audiences->count() }}</x-ui.tag></div>@endif
            </div>
            @if ($audiences->isEmpty())
                <x-ui.empty icon="users" :title="___('No audiences yet.')" />
            @else
                <table class="ui-table">
                    <thead><tr><th>{{ ___('Name') }}</th><th class="ui-table__num">{{ ___('Live count') }}</th><th><span class="ui-visually-hidden">{{ ___('Actions') }}</span></th></tr></thead>
                    <tbody>
                    @foreach($audiences as $a)
                        <tr>
                            <td data-primary>
                                <span class="ui-aud__name">{{ $a->name }}</span>
                                <span class="ui-aud__use">use as <code class="ui-ltr">audience:{{ $a->id }}</code></span>
                            </td>
                            <td class="ui-table__num" data-label="{{ ___('Live count') }}"><strong class="ui-ltr">{{ $a->live_count }}</strong></td>
                            <td class="ui-catalog__row-actions">
                                <form method="post" action="{{ route('crm.audiences.destroy', $a->id) }}?store_id={{ $rid }}">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="ui-icon-btn ui-menu-item__delete" aria-label="{{ ___('Delete') }}" title="{{ ___('Delete') }}"><x-ui.icon name="trash" /></button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        <section class="ui-card ui-card--flush">
            <div class="ui-card__head ui-aud__head">
                <h2 class="ui-card__title">{{ ___('Campaign attribution') }}</h2>
                <span class="ui-aud__note">({{ ___('paid orders within 7 days of send') }})</span>
            </div>
            @if (empty($attribution))
                <x-ui.empty icon="chart" :title="___('No campaigns yet.')" />
            @else
                <table class="ui-table">
                    <thead><tr><th>{{ ___('Campaign') }}</th><th class="ui-table__num">{{ ___('Attributed orders') }}</th><th class="ui-table__num">{{ ___('Revenue') }}</th></tr></thead>
                    <tbody>
                    @foreach($attribution as $row)
                        <tr>
                            <td data-primary>{{ $row['campaign']->subject ?: $row['campaign']->channel }}</td>
                            <td class="ui-table__num" data-label="{{ ___('Attributed orders') }}"><span class="ui-ltr">{{ $row['orders'] }}</span></td>
                            <td class="ui-table__num" data-label="{{ ___('Revenue') }}"><strong class="ui-ltr">{{ number_format($row['revenue'], 2) }}</strong></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    </div>

    <x-ui.card :title="___('New audience')" class="ui-aud__new">
        <form method="post" action="{{ route('crm.audiences.store') }}?store_id={{ $rid }}" class="ui-aud__form">
            @csrf
            <div>
                <label class="ui-label" for="aud-name">{{ ___('Audience name') }}</label>
                <input id="aud-name" name="name" class="ui-input" placeholder="{{ ___('Audience name') }}" required>
            </div>
            <div>
                <label class="ui-label" for="aud-match">{{ ___('Match') }}</label>
                <select id="aud-match" name="match" class="ui-input"><option value="all">{{ ___('All conditions') }}</option><option value="any">{{ ___('Any condition') }}</option></select>
            </div>
            <div id="conds" class="ui-aud__conds">
                <div class="ui-aud__cond">
                    <select name="conditions[0][field]" class="ui-input ui-aud__field" aria-label="{{ ___('Name') }}">
                        <option value="total_spent">{{ ___('Total spent') }}</option>
                        <option value="orders_count">{{ ___('Orders count') }}</option>
                        <option value="last_order_days">{{ ___('Last order (days)') }}</option>
                        <option value="tag">{{ ___('Tag') }}</option>
                        <option value="consent">{{ ___('Marketing consent') }}</option>
                        <option value="birthday_month">{{ ___('Birthday month') }}</option>
                        <option value="loyalty_tier">{{ ___('Loyalty balance ≥') }}</option>
                    </select>
                    <select name="conditions[0][op]" class="ui-input ui-aud__op" aria-label="{{ ___('Match') }}"><option value="gte">≥</option><option value="lte">≤</option><option value="eq">=</option></select>
                    <input name="conditions[0][value]" class="ui-input ui-aud__val" placeholder="{{ ___('value') }}" aria-label="{{ ___('value') }}">
                </div>
            </div>
            <x-ui.button type="submit" icon="plus" block data-ui-busy>{{ ___('Save audience') }}</x-ui.button>
        </form>
    </x-ui.card>
</div>
</div>

@endsection
