{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    P1-23: one template for every JUWRI LTD legal page, English and Arabic (Juwri\Core\Legal\LegalPage). The header
    strip carries the version / effective date from config/legal.php, the "English is binding" note on the Arabic
    text, the 30-day change notice and the legal contact (site.legal.*). Locales without their own text read the
    English one. Unresolved {{FILL}} / {{VERIFY}} markers show as highlighted marks outside production only (the
    controller answers 503 in production; `php artisan legal:check` fails until they are resolved).
--}}
@extends('marketing.layout')

@section('title', $page->title.' - '.brand_title())
@section('meta_description', $page->title)
@section('canonical', url()->current())

@section('content')
@php
    $lgLocale = app()->getLocale();
    $lgActive = function_exists('get_active_languages') ? array_keys(get_active_languages()) : [];
    $lgHasAr = is_file(\Juwri\Core\Legal\LegalPage::path($document, 'ar'));
    $lgDate = $effective ? $effective->locale($lgLocale)->isoFormat('LL') : $page->textDate;
@endphp
<section class="mk-page-hero mk-legal__hero">
    <div class="mk-wrap">
        <span class="mk-eyebrow" data-legal-version>{{ __('site.legal.version', ['version' => $version, 'date' => $lgDate]) }}</span>
        <h1 class="mk-h1">{{ $page->title }}</h1>
        @if ($lgHasAr && in_array('ar', $lgActive, true) && in_array('en', $lgActive, true))
            <p class="mk-legal__langs" data-legal-langs>
                <a href="{{ lang_url('en') }}" hreflang="en" lang="en" @if ($page->locale === 'en') aria-current="true" @endif>English</a>
                <span aria-hidden="true">·</span>
                <a href="{{ lang_url('ar') }}" hreflang="ar" lang="ar" dir="rtl" @if ($page->locale === 'ar') aria-current="true" @endif>العربية</a>
            </p>
        @endif
    </div>
</section>

<section class="mk-page-body mk-legal">
    <div class="mk-wrap">
        <div class="mk-prose" @if ($page->locale === 'ar') lang="ar" dir="rtl" @else lang="en" dir="ltr" @endif>
            <div class="mk-legal__strip" data-legal-strip>
                @if ($page->locale === 'ar')
                    <p data-legal-translation-note>{{ __('site.legal.translation_note') }}</p>
                @elseif (! in_array($lgLocale, ['en', 'ar'], true))
                    <p data-legal-english-only>{{ __('site.legal.english_only') }}</p>
                @endif
                <p data-legal-updated>{{ __('site.legal.updated', ['date' => $lgDate]) }}</p>
                <p>{{ __('site.legal.changes') }}</p>
                <p>{{ __('site.legal.contact') }}</p>
            </div>

            @if ($page->unresolved !== [])
                <div class="mk-legal__todo" role="note" data-legal-unresolved="{{ count($page->unresolved) }}">
                    {{ ___('Not ready to publish: placeholders to fill or promises to confirm') }}: {{ implode(', ', $page->unresolved) }}
                </div>
            @endif

            {!! $page->body !!}
        </div>

        <nav class="mk-legal__nav" aria-label="{{ __('site.footer.legal') }}">
            <h2 class="mk-legal__nav-title">{{ __('site.footer.legal') }}</h2>
            <ul>
                @foreach ($documents as $lgKey => $lgDoc)
                    <li><a href="{{ \Juwri\Core\Legal\LegalDocuments::url($lgKey) }}" @if ($lgKey === $document) aria-current="page" @endif>{{ __($lgDoc['label']) }}</a></li>
                @endforeach
                @if (Route::has('legal.cookies'))
                    <li><a href="{{ route('legal.cookies') }}">{{ __('cookies.title') }}</a></li>
                @endif
            </ul>
        </nav>
    </div>
</section>
@endsection
