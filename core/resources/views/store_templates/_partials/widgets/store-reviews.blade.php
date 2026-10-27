{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    Theme Studio B0.7 — {% widget 'store-reviews' %}. The store's APPROVED reviews
    (same source and gate as the Blade themes' About sheet: StoreReviewsDisplay
    opt-in → features.store_reviews, then StoreReview::approved()->forStore()).
    Read-only — there is no public store-review write endpoint. Renders nothing
    when the store has no approved review yet.
--}}
@php
    $mbwId = 'mbw-store-reviews';
    $mbwBase = \App\Models\StoreReview::approved()->forStore((int) $store->id);
    $mbwCount = (clone $mbwBase)->count();
    $mbwAvg = $mbwCount > 0 ? round((float) (clone $mbwBase)->avg('stars'), 1) : null;
    $mbwReviews = ($mbwCount > 0 && $widgetFirst)
        ? (clone $mbwBase)->latest()->limit(30)->get(['reviewer_name', 'stars', 'body', 'created_at'])
        : collect();
@endphp
@if($mbwCount > 0)
@if($widgetBase)@include('store_templates._partials.widgets._base')@endif
<button type="button" class="mbw-btn mbw-btn--ghost mbw-store-reviews__trigger" data-mbw-open="store-reviews" aria-haspopup="dialog" aria-controls="{{ $mbwId }}-dialog">
    <span aria-hidden="true">★</span>
    <span>{{ $widgetLabel ?? $mbwAvg }}</span>
    <span class="mbw-muted">· {{ $mbwCount }} {{ ___('Reviews') }}</span>
</button>
@if($widgetFirst)
<dialog class="mbw-dialog mbw-store-reviews" id="{{ $mbwId }}-dialog" aria-labelledby="{{ $mbwId }}-title">
    @include('store_templates._partials.widgets._head', ['mbwTitle' => ___('Reviews')])
    <div class="mbw-body">
        <p class="mbw-lead"><span aria-hidden="true">★</span> {{ $mbwAvg }} · {{ $mbwCount }} {{ ___('Reviews') }}</p>
        <ul class="mbw-list">
            @foreach($mbwReviews as $mbwReview)
                <li class="mbw-row mbw-store-reviews__item">
                    <div>
                        <b>{{ $mbwReview->reviewer_name ?: ___('Guest') }}</b>
                        @if(trim((string) $mbwReview->body) !== '')
                            <p class="mbw-muted mbw-store-reviews__body" dir="auto">{{ $mbwReview->body }}</p>
                        @endif
                    </div>
                    <span class="mbw-store-reviews__stars" aria-label="{{ (int) $mbwReview->stars }}/5">{{ str_repeat('★', max(0, min(5, (int) $mbwReview->stars))) }}</span>
                </li>
            @endforeach
        </ul>
    </div>
</dialog>
@endif
@endif
