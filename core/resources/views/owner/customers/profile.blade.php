{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Guest profile'))
@section('page_title', $customer->name ?: ___('Guest'))
@section('page_sub', ___('Full guest story'))

@section('content')

@if(session('success'))<div class="ui-note" data-tone="success">{{ session('success') }}</div>@endif

<div class="ui-form-grid">
    <div>
        <div class="ui-card"><div class="ui-card__body">
            <h4 class="ui-section-title">{{ $customer->name }}</h4>
            <div class="ui-muted">{{ $customer->phone }} · {{ $customer->email }}</div>
            <hr>
            <div class="ui-cluster ui-cluster--between"><span>{{ ___('Orders') }}</span><strong>{{ $customer->orders_count }}</strong></div>
            <div class="ui-cluster ui-cluster--between"><span>{{ ___('Total spent') }}</span><strong>{{ number_format((float)$customer->total_spent, 2) }}</strong></div>
            @if($loyalty)<div class="ui-cluster ui-cluster--between"><span>{{ ___('Loyalty') }}</span><strong>{{ $loyalty->balance }}</strong></div>@endif
            <div>
                @foreach($autoTags as $t)<span class="ui-tag" data-tone="accent">{{ ___(ucfirst(str_replace('_',' ',$t))) }}</span>@endforeach
            </div>
        </div></div>

        <div class="ui-card"><div class="ui-card__body">
            <h4 class="ui-section-title">{{ ___('Preferences & notes') }}</h4>
            <form method="post" action="{{ route('crm.preferences', $customer->id) }}">
                @csrf
                <div><label class="ui-label ui-small">{{ ___('Preferences') }}</label><input name="preferences" class="ui-input ui-input--sm" value="{{ $customer->preferences }}"></div>
                <div><label class="ui-label ui-small">{{ ___('Allergies') }}</label><input name="allergies" class="ui-input ui-input--sm" value="{{ $customer->allergies }}"></div>
                <div><label class="ui-label ui-small">{{ ___('Tags') }}</label><input name="tags" class="ui-input ui-input--sm" value="{{ is_array($customer->tags) ? implode(',', $customer->tags) : $customer->tags }}"></div>
                <div><label class="ui-label ui-small">{{ ___('Notes') }}</label><textarea name="notes" class="ui-input ui-input--sm" rows="3">{{ $customer->notes }}</textarea></div>
                <button class="ui-btn ui-btn--sm">{{ ___('Save') }}</button>
            </form>
        </div></div>
    </div>

    <div>
        <div class="ui-card"><div class="ui-card__body">
            <h4 class="ui-section-title">{{ ___('Timeline') }}</h4>
            <ul class="ui-plain-list">
                @forelse($timeline as $ev)
                    <li class="ui-cluster ui-rule-bottom">
                        <span class="ui-tag ui-caps" data-tone="neutral" style="min-width:90px">{{ $ev['type'] }}</span>
                        <span>{{ $ev['title'] }} <span class="ui-text-2">{{ $ev['detail'] }}</span></span>
                        <span class="ui-muted ui-ms-auto">{{ optional($ev['at'])->diffForHumans() }}</span>
                    </li>
                @empty
                    <li class="ui-text-2">{{ ___('No activity yet.') }}</li>
                @endforelse
            </ul>
        </div></div>
    </div>
</div>

@endsection
