{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('owner.layout')

@section('title', ___('Google reviews'))
@section('page_title', ___('Google reviews'))
@section('page_sub', ___('Read and reply to reviews on your Google Business Profile.'))

@section('page_actions')
    <x-ui.button :href="route('stores.gbp.index').'?store_id='.$rid" variant="secondary" size="sm" icon="external">{{ ___('Google ordering') }}</x-ui.button>
    @include('owner.partials.context-store-select', ['showBranch' => false])
@endsection

@section('content')
@php($aiCopyLang = \Juwri\Modules\Ai\Data\CopyLanguage::storeDefault((int) $rid)->code)
<div class="ui-scope ui-gbp">

@if(session('success'))
    <x-ui.banner tone="success">{{ session('success') }}</x-ui.banner>
@endif
@if(isset($errors) && $errors->any())
    <x-ui.banner tone="danger">{{ $errors->first() }}</x-ui.banner>
@endif

@unless($connected)
    <div class="ui-card">
        <x-ui.empty icon="star" :title="___('Not connected yet')" :body="___('Connect your Google Business location to see and reply to your Google reviews here.')">
            <x-ui.button :href="route('stores.gbp.index').'?store_id='.$rid">{{ ___('Connect Google') }}</x-ui.button>
        </x-ui.empty>
    </div>
@else

{{-- B5: performance widget. Self-contained include; A7 may add the same line to the GBP index page. --}}
@include('owner.gbp.partials.performance', ['rid' => $rid])

<div class="ui-chips ui-gbp__filter">
    <x-ui.chip :href="route('stores.gbp.reviews.index').'?store_id='.$rid" :active="! $unrepliedOnly">{{ ___('All') }}</x-ui.chip>
    <x-ui.chip :href="route('stores.gbp.reviews.index').'?store_id='.$rid.'&unreplied=1'" :active="(bool) $unrepliedOnly" :count="$unrepliedCount">{{ ___('Unreplied') }}</x-ui.chip>
</div>

@if($reviews->isEmpty())
    <div class="ui-card">
        <x-ui.empty icon="star" :title="$unrepliedOnly ? ___('No unreplied reviews. Nice work!') : ___('No Google reviews yet.')" />
    </div>
@else
    <div class="ui-gbp__reviews">
    @foreach($reviews as $review)
        <article class="ui-card ui-gbp__review">
            <div class="ui-gbp__review-head">
                <div class="ui-gbp__who">
                    <strong>{{ $review->reviewer_is_anonymous ? ___('Anonymous') : ($review->reviewer_name ?: ___('Google user')) }}</strong>
                    <span class="ui-gbp__stars" aria-label="{{ $review->star_rating }} / 5">
                        @for($i = 1; $i <= 5; $i++){{ $i <= $review->star_rating ? '★' : '☆' }}@endfor
                    </span>
                </div>
                @if($review->review_created_at)
                    <small class="ui-gbp__hint">{{ $review->review_created_at->diffForHumans() }}</small>
                @endif
            </div>

            @if($review->comment)
                <p class="ui-gbp__comment">{{ $review->comment }}</p>
            @else
                <p class="ui-gbp__comment ui-gbp__comment--empty">{{ ___('(rating only, no comment)') }}</p>
            @endif

            @if($review->reply_comment)
                <div class="ui-gbp__reply">
                    <small>{{ ___('Your reply') }}</small>
                    <span>{{ $review->reply_comment }}</span>
                </div>
                <details class="ui-gbp__edit">
                    <summary>{{ ___('Edit reply') }}</summary>
                    @include('owner.gbp.partials.reply-form', ['review' => $review, 'rid' => $rid])
                </details>
            @else
                @include('owner.gbp.partials.reply-form', ['review' => $review, 'rid' => $rid])
            @endif
        </article>
    @endforeach
    </div>
@endif

@endunless

</div>
@endsection

@if($connected && Route::has('stores.gbp.reviews.draftReply'))
@push('scripts')
<script nonce="{{ csp_nonce() }}">
(function () {
    'use strict';
    // P1-17 (J27-B): "Draft with AI" fills the reply box with a draft; nothing is sent until the owner presses Reply.
    var url = @js(route('stores.gbp.reviews.draftReply').'?store_id='.$rid);
    var token = @js(csrf_token());
    var t = {
        ready: @js(___('Draft ready. Check it, then send your reply.')),
        failed: @js(___('The AI service is unavailable right now. Please try again in a moment.'))
    };
    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('[data-gbp-ai-draft]') : null;
        if (!btn) return;
        e.preventDefault();
        var wrap = btn.closest('[data-gbp-ai]');
        var form = btn.closest('form');
        var box = form ? form.querySelector('[data-gbp-reply-text]') : null;
        if (!wrap || !box || btn.getAttribute('aria-busy') === 'true') return;
        var fd = new FormData();
        fd.append('review_id', wrap.getAttribute('data-review-id'));
        fd.append('lang', (wrap.querySelector('[data-gbp-ai-lang]') || {}).value || '');
        btn.setAttribute('aria-busy', 'true');
        btn.disabled = true;
        var toast = function (msg, type) { if (window.dashToast) window.dashToast(msg, { type: type }); };
        fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' }, body: fd })
            .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d || {} }; }); })
            .then(function (res) {
                if (res.ok && res.d.success) {
                    box.value = res.d.reply;
                    box.setAttribute('dir', res.d.dir || 'auto');
                    box.setAttribute('lang', res.d.lang || '');
                    box.focus();
                    toast(t.ready, 'success');
                    return;
                }
                toast(res.d.error || res.d.message || t.failed, 'error');
            })
            .catch(function () { toast(t.failed, 'error'); })
            .finally(function () { btn.removeAttribute('aria-busy'); btn.disabled = false; });
    });
})();
</script>
@endpush
@endif
