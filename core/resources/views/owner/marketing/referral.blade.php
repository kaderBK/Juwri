{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Referrals'))
@section('page_title', ___('Refer & earn'))
@section('page_sub', ___('Share your code. Earn credit when new owners sign up and convert.'))

@section('content')
@php
    $convList = collect($conversions->items());
    $paid = $convList->where('status', 'paid')->count();
    $pending = $convList->where('status', 'pending')->count();
    $referralCode = $referral->code ?? '';
    $referralLink = route('register', ['ref' => $referralCode]);
@endphp

<div class="ui-scope ui-account ui-ref">
{{-- Hero --}}
<section class="ui-card ui-account__hero">
    <div class="ui-ref__intro">
        <p class="ui-overline">{{ ___('Referrals') }}</p>
        <h2 class="ui-account__name">{{ ___('Your referral link') }}</h2>
        <p class="ui-account__row-sub">{{ ___('Share this link or code anywhere. Track conversions and payouts below.') }}</p>
    </div>
    <dl class="ui-account__facts ui-ref__facts">
        <div><dt>{{ ___('Code') }}</dt><dd class="ui-ltr">{{ $referralCode ?: '—' }}</dd></div>
        <div><dt>{{ ___('Conversions') }}</dt><dd class="ui-ltr">{{ $conversions->total() }}</dd></div>
        <div><dt>{{ ___('Paid') }}</dt><dd class="ui-ltr">{{ $paid }}</dd></div>
        <div><dt>{{ ___('Pending') }}</dt><dd class="ui-ltr">{{ $pending }}</dd></div>
    </dl>
</section>

<x-ui.card :title="___('Share')">
    <label class="ui-label" for="ref-link">{{ ___('Referral link') }}</label>
    <div class="ui-ref__share">
        <input type="text" id="ref-link" class="ui-input ui-ltr" value="{{ $referralLink }}" readonly data-ui-do="select" dir="ltr">
        <x-ui.button variant="secondary" icon="share" data-ui-do="copy" data-ui-copy-target="ref-link" :data-ui-copied="___('Copied')"><span data-ui-copy-label>{{ ___('Copy') }}</span></x-ui.button>
    </div>
    <p class="ui-ref__hint">{{ ___('Click to select. Paste into your messages.') }}</p>
</x-ui.card>

<section class="ui-card ui-card--flush">
    <div class="ui-card__head"><h2 class="ui-card__title">{{ ___('Conversions') }}</h2></div>
    @if ($convList->isEmpty())
        <x-ui.empty icon="users" :title="___('No conversions yet. Share your link to start earning.')" />
    @else
        <table class="ui-table">
            <thead><tr><th>{{ ___('New user') }}</th><th class="ui-table__num">{{ ___('Reward') }}</th><th>{{ ___('Status') }}</th><th>{{ ___('Date') }}</th></tr></thead>
            <tbody>
                @foreach ($convList as $c)
                    <tr>
                        <td data-primary>{{ $c->newUser->username ?? '—' }}</td>
                        <td class="ui-table__num" data-label="{{ ___('Reward') }}"><strong class="ui-ltr">{{ price_format($c->reward_amount ?? 0) }}</strong></td>
                        <td data-label="{{ ___('Status') }}">
                            <x-ui.pill :tone="$c->status === 'paid' ? 'success' : 'warning'">{{ ___($c->status) }}</x-ui.pill>
                        </td>
                        <td data-label="{{ ___('Date') }}">{{ $c->created_at?->diffForHumans() }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if ($conversions->hasPages())
            <div class="ui-catalog__pager">{{ $conversions->links() }}</div>
        @endif
    @endif
</section>
</div>
@endsection
