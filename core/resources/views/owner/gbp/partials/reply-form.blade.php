{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

<form method="post" action="{{ route('stores.gbp.reviews.reply') }}?store_id={{ $rid }}" class="ui-gbp__reply-form">
    @csrf
    <input type="hidden" name="review_id" value="{{ $review->id }}">
    <label class="ui-visually-hidden" for="gbp-reply-{{ $review->id }}">{{ ___('Write a reply…') }}</label>
    <textarea id="gbp-reply-{{ $review->id }}" name="text" class="ui-input" rows="2" maxlength="4096" required dir="auto" data-gbp-reply-text
        placeholder="{{ ___('Write a reply…') }}">{{ $review->reply_comment }}</textarea>
    @if(Route::has('stores.gbp.reviews.draftReply'))
    {{-- P1-17 (J27-B): AI reply draft — fills the box only; the owner sends it with the button below. --}}
    <div class="ui-ai-draft" data-gbp-ai data-review-id="{{ $review->id }}">
        <select class="ui-input ui-input--sm ui-ai-draft__lang" data-gbp-ai-lang aria-label="{{ ___('Language') }}">
            <option value="ar" lang="ar" @selected(($aiCopyLang ?? 'en') === 'ar')>العربية</option>
            <option value="en" lang="en" @selected(($aiCopyLang ?? 'en') === 'en')>English</option>
        </select>
        <x-ui.button variant="secondary" size="sm" icon="sparkle" data-gbp-ai-draft>{{ ___('Draft with AI') }}</x-ui.button>
    </div>
    @endif
    <div><x-ui.button type="submit" size="sm" data-ui-busy>{{ $review->reply_comment ? ___('Update reply') : ___('Reply on Google') }}</x-ui.button></div>
</form>
