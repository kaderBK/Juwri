{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Merge duplicate customers'))
@section('page_title', ___('Merge duplicate customers'))
@section('page_sub', ___('Fold a duplicate profile into the one you keep. Real accounts (with a password) are never merged away.'))

@section('page_actions')
    <x-ui.button :href="route('customers.index')" variant="ghost" icon="back">{{ ___('Customers') }}</x-ui.button>
@endsection

@section('content')
@if(session('success'))<x-ui.banner tone="success">{{ session('success') }}</x-ui.banner>@endif
@if(session('error'))<x-ui.banner tone="danger">{{ session('error') }}</x-ui.banner>@endif

@if(empty($pairs))
    <x-ui.empty :title="___('No duplicate customers found.')" />
@else
    @foreach($pairs as $pair)
        <x-ui.card>
<div class="ui-card__head">{{ ___('Possible duplicate') }} · <span class="ui-text-2">{{ $pair['reason'] }}</span></div>
            <div class="ui-form-grid">
                @foreach([['c' => $pair['primary'], 'label' => ___('Keep'), 'variant' => 'success'], ['c' => $pair['duplicate'], 'label' => ___('Remove'), 'variant' => 'warning']] as $side)
                    @php($c = $side['c'])
                    <div>
                        <div class="ui-bordered ui-fill">
                            <div class="ui-cluster ui-cluster--between ui-cluster--top">
                                <strong>{{ $c['name'] ?: ___('Unnamed') }}</strong>
                                <x-ui.tag :tone="$side['variant']">{{ $side['label'] }}</x-ui.tag>
                            </div>
                            <div class="ui-muted">{{ $c['email'] }}</div>
                            <div class="ui-muted">{{ $c['phone'] }}</div>
                            <div class="ui-cluster ui-small">
                                <span>{{ ___('Orders') }}: <strong>{{ $c['orders'] }}</strong></span>
                                <span>{{ ___('Points') }}: <strong>{{ $c['points'] }}</strong></span>
                            </div>
                            <div class="ui-muted">{{ ___('Last seen') }}: {{ $c['last_seen'] ? $c['last_seen']->format('Y-m-d') : '—' }}</div>
                            @if($c['has_password'])
                                <div class="ui-small ui-text-accent">{{ ___('Real account (password set)') }}</div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="ui-cluster ui-cluster--end">
                @if($pair['duplicate']['has_password'])
                    <span class="ui-muted">{{ ___('The duplicate is a real account and cannot be merged away.') }}</span>
                @else
                    <form method="post" action="{{ route('customers.merge') }}"
                          data-confirm="{{ ___('Merge the removed profile into the kept one? This cannot be undone.') }}" data-confirm-danger>
                        @csrf
                        <input type="hidden" name="primary_id" value="{{ $pair['primary']['id'] }}">
                        <input type="hidden" name="duplicate_id" value="{{ $pair['duplicate']['id'] }}">
                        <input type="hidden" name="confirm" value="1">
                        <x-ui.button type="submit" icon="check">{{ ___('Merge') }}</x-ui.button>
                    </form>
                @endif
            </div>
        </x-ui.card>
    @endforeach
@endif
@endsection
