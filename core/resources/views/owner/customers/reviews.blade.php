{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Reviews'))
@section('page_title', ___('Item reviews'))
@section('page_sub', $store->title.' - '.___('Diner ratings and feedback per menu item.'))

@section('content')
@php
    $items = collect($reviews->items());
    $pending = $items->where('status', 'pending')->count();
    $approved = $items->where('status', 'approved')->count();
    $rejected = $items->where('status', 'rejected')->count();
    $avg = $items->where('status', 'approved')->avg('stars');
@endphp

<div class="ui-scope ui-reviews">
    {{-- Summary: average rating as the hero figure, moderation counts beside it. --}}
    <section class="ui-reviews__summary" aria-label="{{ ___('What diners are saying') }}">
        <div class="ui-reviews__avg">
            <span class="ui-reviews__avg-value">{{ $avg ? number_format($avg, 1) : '—' }}</span>
            <span class="ui-reviews__stars" aria-hidden="true">
                @for ($i = 1; $i <= 5; $i++)
                    <x-ui.icon name="star" size="16" :class="($avg && $i <= round($avg)) ? 'is-on' : ''" />
                @endfor
            </span>
            <span class="ui-reviews__avg-label">{{ ___('Avg rating') }}</span>
        </div>
        <div class="ui-reviews__counts">
            <div class="ui-stat" data-tone="warning"><span class="ui-stat__value">{{ $pending }}</span><span class="ui-stat__label">{{ ___('Pending') }}</span></div>
            <div class="ui-stat" data-tone="success"><span class="ui-stat__value">{{ $approved }}</span><span class="ui-stat__label">{{ ___('Approved') }}</span></div>
            <div class="ui-stat" data-tone="danger"><span class="ui-stat__value">{{ $rejected }}</span><span class="ui-stat__label">{{ ___('Rejected') }}</span></div>
        </div>
        <p class="ui-reviews__hint">{{ ___('Approve, reject, and reply to per-item ratings.') }}</p>
    </section>

    @if ($items->isEmpty())
        <div class="ui-card">
            <x-ui.empty :title="___('No reviews yet')" :body="___('Diner ratings on menu items appear here for moderation.')" icon="star" />
        </div>
    @else
        <div class="ui-reviews__list">
            @foreach ($items as $review)
                @php
                    $tone = match ($review->status) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default    => 'warning',
                    };
                @endphp
                <article class="ui-review" data-status="{{ $review->status }}">
                    <header class="ui-review__head">
                        <div>
                            <strong class="ui-review__item">{{ $review->menu->name ?? '—' }}</strong>
                            <span class="ui-reviews__stars" aria-label="{{ $review->stars }} {{ ___('stars') }}">
                                @for ($i = 1; $i <= 5; $i++)
                                    <x-ui.icon name="star" size="14" :class="$i <= $review->stars ? 'is-on' : ''" />
                                @endfor
                                <span class="ui-review__score ui-ltr">{{ $review->stars }}/5</span>
                            </span>
                        </div>
                        <div class="ui-review__meta">
                            <x-ui.pill :tone="$tone">{{ ___($review->status) }}</x-ui.pill>
                            <small>{{ $review->created_at?->diffForHumans() }}</small>
                        </div>
                    </header>
                    @if ($review->body)
                        <p class="ui-review__body">{{ $review->body }}</p>
                    @endif
                    <div class="ui-review__actions">
                        @if ($review->status !== 'approved')
                            <form action="{{ route('dashboard.reviews.update', [$store->id, $review->id]) }}" method="post">
                                @csrf
                                <input type="hidden" name="status" value="approved">
                                <x-ui.button type="submit" size="sm" variant="success" icon="check">{{ ___('Approve') }}</x-ui.button>
                            </form>
                        @endif
                        @if ($review->status !== 'rejected')
                            <form action="{{ route('dashboard.reviews.update', [$store->id, $review->id]) }}" method="post">
                                @csrf
                                <input type="hidden" name="status" value="rejected">
                                <x-ui.button type="submit" size="sm" variant="danger" icon="x">{{ ___('Reject') }}</x-ui.button>
                            </form>
                        @endif
                    </div>
                </article>
            @endforeach
        </div>

        @if ($reviews->hasPages())
            <div>{{ $reviews->links() }}</div>
        @endif
    @endif
</div>
@endsection
