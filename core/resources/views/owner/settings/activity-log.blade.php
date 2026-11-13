{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Activity Log'))
@section('page_title', ___('Activity Log'))
@section('page_sub', ___('Audit trail of menu, order, and account actions across your stores.'))

@section('content')
@php
    $actionMap = [
        'menu'       => ['icon' => 'products', 'label' => ___('Menu items')],
        'category'   => ['icon' => 'list',     'label' => ___('Categories')],
        'order'      => ['icon' => 'orders',   'label' => ___('Orders')],
        'restaurant' => ['icon' => 'store',    'label' => ___('Store')],
        'csv'        => ['icon' => 'download', 'label' => ___('CSV')],
        'coupon'     => ['icon' => 'gift',     'label' => ___('Coupons')],
        'review'     => ['icon' => 'star',     'label' => ___('Reviews')],
        'staff'      => ['icon' => 'users',    'label' => ___('Staff')],
        'items'      => ['icon' => 'products', 'label' => ___('Items')],
        'giftcard'   => ['icon' => 'gift',     'label' => ___('Gift cards')],
        'wallet'     => ['icon' => 'wallet',   'label' => ___('Wallet')],
        'loyalty'    => ['icon' => 'star',     'label' => ___('Loyalty')],
        'table'      => ['icon' => 'store',    'label' => ___('Tables')],
        'booking'    => ['icon' => 'clock',    'label' => ___('Bookings')],
        'auth'       => ['icon' => 'info',     'label' => ___('Sign-in')],
    ];
    $activeRestaurant = request('store_id');
    $activeAction = request('action');
@endphp

<div class="ui-scope ui-account ui-alog">
{{-- Hero --}}
<section class="ui-card ui-account__hero">
    <div class="ui-alog__intro">
        <p class="ui-overline">{{ ___('Audit') }}</p>
        <h2 class="ui-account__name">{{ ___('What happened, when, and where') }}</h2>
        <p class="ui-account__row-sub">{{ ___('Every menu update, order change, and account action is captured here.') }}</p>
    </div>
    <dl class="ui-account__facts">
        <div><dt>{{ ___('Entries on page') }}</dt><dd class="ui-ltr">{{ number_format($logs->count()) }}</dd></div>
        <div><dt>{{ ___('Total entries') }}</dt><dd class="ui-ltr">{{ number_format($logs->total()) }}</dd></div>
        <div><dt>{{ ___('Stores') }}</dt><dd class="ui-ltr">{{ number_format($stores->count()) }}</dd></div>
    </dl>
</section>

<x-ui.card>
    <form class="ui-alog__filters" method="get" action="{{ route('activity-log.index') }}">
        <div class="ui-alog__field">
            <label class="ui-label" for="alog-store">{{ ___('Store') }}</label>
            <select id="alog-store" name="store_id" class="ui-input">
                <option value="">{{ ___('All stores') }}</option>
                @foreach ($stores as $r)
                    <option value="{{ $r->id }}" @selected($activeRestaurant == $r->id)>{{ $r->title }}</option>
                @endforeach
            </select>
        </div>
        <div class="ui-alog__field">
            <label class="ui-label" for="alog-action">{{ ___('Action') }}</label>
            <select id="alog-action" name="action" class="ui-input">
                <option value="">{{ ___('All actions') }}</option>
                @foreach ($prefixes as $key)
                    <option value="{{ $key }}" @selected($activeAction === $key)>{{ $actionMap[$key]['label'] ?? ucfirst(str_replace('_', ' ', $key)) }}</option>
                @endforeach
            </select>
        </div>
        <div class="ui-alog__field">
            <label class="ui-label" for="alog-actor">{{ ___('Who') }}</label>
            <select id="alog-actor" name="actor" class="ui-input">
                <option value="">{{ ___('Everyone') }}</option>
                @foreach ($actors as $a)
                    <option value="{{ $a->id }}" @selected((string) request('actor') === (string) $a->id)>{{ $a->name ?: $a->email }}</option>
                @endforeach
            </select>
        </div>
        <div class="ui-alog__field">
            <label class="ui-label" for="alog-from">{{ ___('From') }}</label>
            <input id="alog-from" type="date" name="from" value="{{ request('from') }}" class="ui-input">
        </div>
        <div class="ui-alog__field">
            <label class="ui-label" for="alog-to">{{ ___('To') }}</label>
            <input id="alog-to" type="date" name="to" value="{{ request('to') }}" class="ui-input">
        </div>
        <div class="ui-alog__btns">
            <x-ui.button type="submit" icon="filter">{{ ___('Filter') }}</x-ui.button>
            @if (request()->hasAny(['store_id', 'action', 'actor', 'from', 'to']))
                <x-ui.button :href="route('activity-log.index')" variant="secondary" icon="x">{{ ___('Clear') }}</x-ui.button>
            @endif
            <x-ui.button :href="route('activity-log.index', array_merge(request()->query(), ['format' => 'csv']))" variant="secondary" icon="download">{{ ___('Export CSV') }}</x-ui.button>
        </div>
    </form>
</x-ui.card>

@if ($logs->isEmpty())
    <div class="ui-card">
        <x-ui.empty icon="clock" :title="___('No activity yet')" :body="___('Menu edits, order changes, and account actions appear here as soon as they happen.')" />
    </div>
@else
    <ol class="ui-list ui-alog__list">
        @foreach ($logs as $log)
            @php
                $prefix = explode('.', $log->action)[0] ?? '';
                $meta = $actionMap[$prefix] ?? ['icon' => 'info', 'label' => $log->action_label];
            @endphp
            <li class="ui-alog__item">
                <span class="ui-row__icon" aria-hidden="true"><x-ui.icon :name="$meta['icon']" size="20" /></span>
                <div class="ui-alog__body">
                    <div class="ui-alog__top">
                        <div class="ui-alog__what">
                            <strong>{{ $log->action_label }}</strong>
                            @if ($log->store)
                                <span class="ui-alog__store">{{ $log->store->title }}</span>
                            @endif
                        </div>
                        <time class="ui-alog__when" datetime="{{ $log->created_at?->toIso8601String() }}" title="{{ $log->created_at }}">
                            {{ $log->created_at?->diffForHumans() }}
                        </time>
                    </div>
                    @if ($log->meta)
                        @php $__m = (array) $log->meta; @endphp
                        {{-- E12: before/after diff when the action captured old/new. --}}
                        @if (isset($__m['old']) && isset($__m['new']))
                            <ul class="ui-alog__meta" data-audit-diff>
                                @foreach (array_keys(((array) $__m['new']) + ((array) $__m['old'])) as $field)
                                    <li>
                                        <span class="ui-alog__key">{{ $field }}</span>
                                        <code class="ui-alog__old">{{ is_array(data_get($__m['old'], $field)) ? json_encode(data_get($__m['old'], $field)) : (data_get($__m['old'], $field) ?? '—') }}</code>
                                        <span aria-hidden="true" class="ui-alog__arrow">&rarr;</span>
                                        <code class="ui-alog__new">{{ is_array(data_get($__m['new'], $field)) ? json_encode(data_get($__m['new'], $field)) : (data_get($__m['new'], $field) ?? '—') }}</code>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        <ul class="ui-alog__meta">
                            @foreach ($__m as $k => $v)
                                @if (! in_array($k, ['old', 'new'], true))
                                    <li><span class="ui-alog__key">{{ $k }}</span> <code>{{ is_array($v) ? json_encode($v) : $v }}</code></li>
                                @endif
                            @endforeach
                        </ul>
                    @endif
                    @if ($log->ip)
                        <p class="ui-alog__ip">{{ ___('IP') }}: <span class="ui-ltr">{{ $log->ip }}</span></p>
                    @endif
                </div>
            </li>
        @endforeach
    </ol>

    @if ($logs->hasPages())
        <div class="ui-catalog__pager">
            {{ $logs->appends(request()->query())->links() }}
        </div>
    @endif
@endif
</div>
@endsection
