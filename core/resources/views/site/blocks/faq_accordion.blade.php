{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Site block: faq_accordion. Same locale-filtered query as the home page;
     settings.limit bounds the row count (default 10); optional heading
     override. FAQPage JSON-LD is emitted by site/page.blade.php. --}}
@php
    $sbLimit = (int) ($block['settings']['limit'] ?? 10) ?: 10;
    try {
        $faqs = \Juwri\Modules\Content\Models\Faq::where('active', 1)->where(function ($query) {
            $query->where('translation_lang', get_lang())->orWhereNull('translation_lang');
        })->limit($sbLimit)->get();
    } catch (\Throwable $e) {
        $faqs = collect();
    }
    $sbHeading = (string) ($block['content']['heading'] ?? '');
@endphp
@if ($faqs->count())
    <section class="mk-sec mk-sec--tint sb-block" data-site-block="faq_accordion">
        <div class="mk-wrap">
            <div class="mk-sec__head">
                <h2 class="mk-h2">{{ $sbHeading !== '' ? $sbHeading : ___('Everything you need to know') }}</h2>
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
