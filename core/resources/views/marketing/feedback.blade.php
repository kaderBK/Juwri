{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('marketing.layout')

@section('title', ___('Share your feedback') . ' - ' . seo_brand())
@section('meta_description', ___('Tell us what to improve. Your feedback shapes the product.'))

@section('content')
    @include('marketing.partials.page-hero', [
        'title' => ___('Tell us what you think'),
        'eyebrow' => ___('Feedback'),
        'sub' => ___('Your feedback helps us improve. Share a rating and any comments below.'),
    ])

    <section class="mk-sec">
        <div class="mk-wrap">
            <div class="mk-formcol">
                {{-- Flash: the send handler reports through app_alert_*(); a plain
                     'success' flash is honoured too. --}}
                @php
                    $mkOk = session('success') ?: (session('app_alert_type') === 'success' ? session('app_alert_message') : null);
                    $mkErr = session('app_alert_type') === 'error' ? session('app_alert_message') : null;
                    $mkRating = (int) old('rating', 0);
                @endphp
                @if ($mkOk)
                    <div class="mk-alert" role="status">{{ $mkOk }}</div>
                @endif
                @if ($mkErr)
                    <div class="mk-alert mk-alert--error" role="alert">{{ $mkErr }}</div>
                @endif

                <form method="POST" action="{{ Route::has('feedback.send') ? route('feedback.send') : url('/feedback') }}" id="feedbackForm" class="mk-form">
                    @csrf
                    @include('partials.abuse-signup-fields')
                    {{-- The shared contact/feedback request requires a subject; the
                         feedback form has no subject box, so it sends a fixed one. --}}
                    <input type="hidden" name="subject" value="{{ ___('Feedback') }}">

                    <div class="mk-form__row">
                        <x-ui.field id="feedback-name" name="name" :label="___('Your name')" autocomplete="name" required />
                        <x-ui.field id="feedback-email" name="email" type="email" :label="___('Email')" autocomplete="email" required />
                    </div>

                    {{-- Star rating: native radios (no script). Highest first in the
                         DOM; CSS shows them 1 → 5. Unrated submits 0, as before. --}}
                    <fieldset class="mk-rating">
                        <legend>{{ ___('Rating') }}</legend>
                        <input type="hidden" name="rating" value="0">
                        <div class="mk-rating__stars">
                            @for ($i = 5; $i >= 1; $i--)
                                <input type="radio" name="rating" id="rating-{{ $i }}" value="{{ $i }}" @checked($mkRating === $i)>
                                <label for="rating-{{ $i }}"><span aria-hidden="true">★</span><span class="mk-sr">{{ $i }} {{ ___('stars') }}</span></label>
                            @endfor
                        </div>
                        @error('rating')<span class="mk-rating__err">{{ $message }}</span>@enderror
                    </fieldset>

                    <x-ui.field id="feedback-message" name="message" type="textarea" rows="5" :label="___('Comments')" :placeholder="___('What did you like? What could be better?')" required />

                    @if (function_exists('display_captcha'))
                        {!! display_captcha() !!}
                        @error('g-recaptcha-response')<p class="mk-alert mk-alert--error">{{ $message }}</p>@enderror
                    @endif
                    @error('subject')<p class="mk-alert mk-alert--error">{{ $message }}</p>@enderror

                    <div class="mk-form__foot">
                        <button type="submit" class="ui-btn ui-btn--lg">
                            <span>{{ ___('Submit feedback') }}</span>
                            <x-ui.icon name="arrow-right" size="18" flip />
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </section>
@endsection
