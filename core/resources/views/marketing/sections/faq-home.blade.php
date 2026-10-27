{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@if (! empty($faqs) && $faqs->count())
    <section class="mk-sec mk-sec--tint">
        <div class="mk-wrap">
            <div class="mk-sec__head">
                <h2 class="mk-h2">{{ ___('Everything you need to know') }}</h2>
                <p class="mk-lead">{{ ___('Answers to the questions restaurants ask us most.') }}</p>
            </div>
            @include('marketing.sections.faq', ['faqs' => $faqs])
            <div class="mk-actions">
                @if (Route::has('faqs'))<a href="{{ route('faqs') }}" class="ui-btn ui-btn--secondary">{{ ___('See all FAQs') }}</a>@endif
                @if (Route::has('contact'))<a href="{{ route('contact') }}" class="ui-btn ui-btn--ghost">{{ ___('Still have a question? Talk to us') }}</a>@endif
            </div>
        </div>
    </section>
@endif
