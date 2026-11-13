{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- DIRECTORY T2-3: owner "Reviews" panel — read own approved store_reviews
     + reply once (editable within 24h). Moderation itself is superadmin-only
     (D3), so there is no approve/reject control here — see
     owner/customers/reviews.blade.php for the (separate) item-review
     moderation the owner does control. --}}
@extends('owner.layout')

@section('title', ___('Store reviews'))
@section('page_title', ___('Store reviews'))
@section('page_sub', ___('What customers say about :store, with your replies.', ['store' => $store->title]))

@section('content')
<div class="ui-scope ui-sreviews">
    @if ($reviews->isEmpty())
        <div class="ui-card">
            <x-ui.empty icon="star" :title="___('No published reviews yet.')" />
        </div>
    @else
        @foreach ($reviews as $review)
            <article class="ui-card ui-sreviews__item">
                <header class="ui-sreviews__head">
                    <div class="ui-sreviews__who" dir="auto">
                        <strong>{{ $review->reviewer_name ?: ___('Anonymous') }}</strong>
                        @if ($review->is_verified)
                            <x-ui.pill tone="success">{{ ___('Verified') }}</x-ui.pill>
                        @endif
                    </div>
                    <small class="ui-sreviews__helpful">{{ ___('Helpful') }}: <span class="ui-ltr">{{ (int) $review->helpful_count }}</span></small>
                </header>
                <div class="ui-sreviews__stars" role="img" aria-label="{{ (int) $review->stars }}/5">{{ str_repeat('★', (int) $review->stars) }}<span>{{ str_repeat('☆', 5 - (int) $review->stars) }}</span></div>

                @if ($review->body)
                    <p class="ui-sreviews__body" dir="auto">{{ $review->body }}</p>
                @endif

                @if ($review->hasOwnerReply())
                    <div class="ui-sreviews__reply">
                        <span class="ui-overline">{{ ___('Your reply') }}</span>
                        <p dir="auto">{{ $review->owner_reply }}</p>
                    </div>
                @endif

                @php
                    $replyLocked = $review->owner_replied_at && $review->owner_replied_at->diffInHours(now()) >= 24;
                @endphp
                @if (!$replyLocked)
                    <form method="POST" action="{{ route('dashboard.store-reviews.reply', [$store->id, $review->id]) }}" class="ui-sreviews__form">
                        @csrf
                        <input type="text" name="reply" maxlength="1000" class="ui-input"
                               value="{{ old('reply', $review->owner_reply) }}" aria-label="{{ ___('Your reply') }}"
                               placeholder="{{ $review->hasOwnerReply() ? ___('Edit your reply…') : ___('Write a reply…') }}">
                        <x-ui.button type="submit" size="sm" icon="chat">{{ $review->hasOwnerReply() ? ___('Update') : ___('Reply') }}</x-ui.button>
                    </form>
                    @error('reply')<p class="ui-sreviews__error">{{ $message }}</p>@enderror
                @else
                    <p class="ui-sreviews__muted">{{ ___('The 24-hour reply window has closed.') }}</p>
                @endif
            </article>
        @endforeach

        @if ($reviews->hasPages())
            <div class="ui-catalog__pager">{{ $reviews->links() }}</div>
        @endif
    @endif
</div>
@endsection
