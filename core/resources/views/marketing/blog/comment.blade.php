{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- One blog comment with its approved replies (recursive). Pass $comment. --}}
@php
    $comment = $comment ?? null;
@endphp
@if ($comment)
    <article class="mk-comment">
        <header class="mk-comment__head">
            <span class="mk-comment__avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr((string) ($comment->name ?? $comment->user->name ?? ___('Guest')), 0, 1)) }}</span>
            <strong>{{ $comment->name ?? $comment->user->name ?? ___('Guest') }}</strong>
            <time datetime="{{ $comment->created_at?->toIso8601String() }}">{{ $comment->created_at?->diffForHumans() }}</time>
        </header>
        <p class="mk-comment__body">{{ $comment->comment }}</p>

        @if (! empty($comment->replies) && $comment->replies->count())
            <div class="mk-comment__replies">
                @foreach ($comment->replies as $reply)
                    @include('marketing.blog.comment', ['comment' => $reply])
                @endforeach
            </div>
        @endif
    </article>
@endif
