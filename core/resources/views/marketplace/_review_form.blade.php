{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- stores P3-1: shared review write form. Vars:
       $action (POST route), $eligible (bool), $myReview (MarketplaceReview|null),
       $ineligibleHint (string). Escaped output only — reviews are hostile input. --}}
<style nonce="{{ csp_nonce() }}">
    .review-form { display:flex; flex-direction:column; gap:12px; max-width:560px; }
    .review-form input[type=text], .review-form textarea { padding:10px 14px; border:1px solid var(--line); border-radius:10px; background:var(--bg); color:var(--ink); font:inherit; }
    .stars-input { display:inline-flex; flex-direction:row-reverse; justify-content:flex-end; gap:2px; }
    .stars-input input { position:absolute; width:1px; height:1px; opacity:0; }
    .stars-input label { font-size:26px; color:var(--line); cursor:pointer; line-height:1; }
    .stars-input input:checked ~ label,
    .stars-input label:hover, .stars-input label:hover ~ label { color:var(--star); }
    .review-flash { background:#f0f7f0; border:1px solid #cfe6cf; color:#2f6f2f; border-radius:10px; padding:10px 14px; margin:0 0 12px; }
</style>
<section class="block">
    <h2>{{ ___('Write a review') }}</h2>

    @if(session('review_status') === 'pending')
        @if(session('review_edited'))
            <p class="review-flash">{{ ___('Your edit is awaiting moderation.') }}</p>
        @else
            <p class="review-flash">{{ ___('Thanks! Your review is pending moderation and will appear once approved.') }}</p>
        @endif
    @endif

    @if($errors->has('review'))
        <p class="review-flash" style="background:#fdf3f3;border-color:#e8c2c2;color:#8a2f2f;">{{ $errors->first('review') }}</p>
    @endif

    @auth
        @if($eligible)
            @if($myReview && $myReview->status === 'pending' && session('review_status') !== 'pending')
                <p class="muted" style="margin-top:0;">{{ ___('Your review is pending moderation.') }}</p>
            @endif
            <form method="post" action="{{ $action }}" class="review-form">@csrf
                <div class="stars-input" aria-label="{{ ___('Rating') }}">
                    @for($i = 5; $i >= 1; $i--)
                        <input type="radio" id="r{{ $i }}" name="rating" value="{{ $i }}" @checked((int) ($myReview->rating ?? 0) === $i) required>
                        <label for="r{{ $i }}" title="{{ $i }}">★</label>
                    @endfor
                </div>
                <input type="text" name="title" maxlength="120" dir="auto" placeholder="{{ ___('Title (optional)') }}" value="{{ $myReview->title ?? '' }}">
                <textarea name="body" maxlength="2000" rows="4" dir="auto" placeholder="{{ ___('Share your experience…') }}">{{ $myReview->body ?? '' }}</textarea>
                <button class="btn" type="submit" style="align-self:flex-start;">{{ $myReview ? ___('Update review') : ___('Submit review') }}</button>
            </form>
        @else
            <p class="muted" style="margin:0;">{{ $ineligibleHint }}</p>
        @endif
    @else
        <p class="muted" style="margin:0;"><a href="{{ route('login') }}">{{ ___('Sign in') }}</a> {{ ___('to write a review.') }}</p>
    @endauth
</section>
