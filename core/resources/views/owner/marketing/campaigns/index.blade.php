{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Marketing campaigns'))
@section('page_title', ___('Marketing campaigns'))
@section('page_sub', ___('Email and SMS broadcasts to consented diners. Track delivery and engagement per send.'))

@section('page_actions')
    <x-ui.button :href="route('campaigns.create')" icon="plus">
        {{ ___('Create campaign') }}
    </x-ui.button>
@endsection

@section('content')
@php
    $items = collect($campaigns->items());
    $sentTotal   = $items->sum('sent_count');
    $failedTotal = $items->sum('failed_count');
    $scheduledCt = $items->where('status', 'scheduled')->count();
@endphp

<div class="ui-scope ui-camp">
    <section class="ui-camp__stats" aria-label="{{ ___('Marketing campaigns') }}">
        <x-ui.stat :label="___('Subscribers')" :value="number_format($totalSubscribers)" icon="users" />
        <x-ui.stat :label="___('Sent (page)')" :value="number_format($sentTotal)" icon="check" tone="success" />
        <x-ui.stat :label="___('Failed (page)')" :value="number_format($failedTotal)" icon="alert" tone="danger" />
        <x-ui.stat :label="___('Scheduled')" :value="number_format($scheduledCt)" icon="clock" tone="warning" />
    </section>

    @if ($items->isEmpty())
        <div class="ui-card">
            <x-ui.empty icon="share" :title="___('No campaigns yet')"
                :body="___('Launch an email or SMS blast to your consented customers to drive repeat visits and showcase new menu items.')">
                <x-ui.button :href="route('campaigns.create')" icon="plus">{{ ___('Create first campaign') }}</x-ui.button>
            </x-ui.empty>
        </div>
    @else
        <section class="ui-camp__wrap">
            <ul class="ui-list ui-camp__list">
                @foreach ($items as $campaign)
                    @php
                        $title = $campaign->channel === 'email' && $campaign->subject
                            ? $campaign->subject
                            : Str::limit($campaign->body, 80);
                    @endphp
                    <li>
                        <a class="ui-row ui-camp__row" href="{{ route('campaigns.show', $campaign->id) }}" aria-label="{{ ___('Report') }}: {{ $title }}">
                            <span class="ui-row__icon"><x-ui.icon :name="$campaign->channel === 'email' ? 'share' : 'chat'" size="22" /></span>
                            <span class="ui-row__body">
                                <span class="ui-row__title" dir="auto">{{ $title }}</span>
                                <span class="ui-camp__meta">
                                    <x-ui.tag>{{ strtoupper($campaign->channel) }}</x-ui.tag>
                                    <span>{{ ___('Segment') }}: <strong>{{ ___($campaign->segment) }}</strong></span>
                                    <span>{{ ___('Created') }}: {{ $campaign->created_at?->diffForHumans() }}</span>
                                    @if ($campaign->scheduled_at && $campaign->status === 'scheduled')
                                        <span>{{ ___('Scheduled') }}: <span class="ui-ltr">{{ $campaign->scheduled_at->format('Y-m-d H:i') }}</span></span>
                                    @endif
                                </span>
                            </span>
                            <span class="ui-row__trail ui-camp__trail">
                                <span class="ui-camp__count">
                                    <small>{{ ___('Sent') }}</small>
                                    <strong class="ui-ltr">{{ number_format($campaign->sent_count) }}</strong>
                                </span>
                                @if ($campaign->failed_count > 0)
                                    <span class="ui-camp__count" data-tone="danger">
                                        <small>{{ ___('Failed') }}</small>
                                        <strong class="ui-ltr">{{ number_format($campaign->failed_count) }}</strong>
                                    </span>
                                @endif
                                <x-ui.pill :tone="\Juwri\Core\Support\Views\OwnerOpsViewHelpers::campaignStatusVariant($campaign->status)">{{ ___($campaign->status) }}</x-ui.pill>
                                <x-ui.icon name="chevron" size="18" class="ui-row__chevron" />
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
            @if ($campaigns->hasPages())
                <div class="ui-catalog__pager">{{ $campaigns->links() }}</div>
            @endif
        </section>
    @endif
</div>
@endsection
