{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('marketing.layout')

@section('title', seo_title_override('contact', ___('Contact us')) . ' - ' . seo_brand())
@section('meta_description', ___('Questions about digital menus or online ordering? Talk to our team, we reply fast.'))

@push('structured_data')
{!! seo_breadcrumbs_jsonld([
    ['name' => ___('Home'), 'url' => route('home')],
    ['name' => ___('Contact Us')],
]) !!}
@endpush

@section('content')
    @include('marketing.partials.page-hero', [
        'title' => ___('Get in touch'),
        'eyebrow' => ___('Contact Us'),
        'sub' => ___('Drop us a line and we will reply within one business day.'),
    ])

    <section class="mk-sec">
        <div class="mk-wrap">
            <div class="mk-formcol">
                {{-- Flash: the send handler reports through app_alert_*(); a plain
                     'success' flash is honoured too. --}}
                @php
                    $mkOk = session('success') ?: (session('app_alert_type') === 'success' ? session('app_alert_message') : null);
                    $mkErr = session('app_alert_type') === 'error' ? session('app_alert_message') : null;
                @endphp
                @if ($mkOk)
                    <div class="mk-alert" role="status">{{ $mkOk }}</div>
                @endif
                @if ($mkErr)
                    <div class="mk-alert mk-alert--error" role="alert">{{ $mkErr }}</div>
                @endif

                <form method="POST" action="{{ Route::has('contact.send') ? route('contact.send') : url('/contact') }}" class="mk-form">
                    @csrf
                    @include('partials.abuse-signup-fields')

                    <div class="mk-form__row">
                        <x-ui.field id="contact-name" name="name" :label="___('Your name')" autocomplete="name" required />
                        <x-ui.field id="contact-email" name="email" type="email" :label="___('Email')" autocomplete="email" required />
                    </div>
                    {{-- A4: the storefront "Report this store" link lands here with ?report={slug}. --}}
                    @php $__report = preg_replace('/[^A-Za-z0-9_-]/', '', mb_substr((string) request('report', ''), 0, 100)); @endphp
                    <x-ui.field id="contact-subject" name="subject" :label="___('Subject')" :value="$__report !== '' ? ___('Report a store: :slug', ['slug' => $__report]) : null" required />
                    <x-ui.field id="contact-message" name="message" type="textarea" rows="5" :label="___('Message')" :placeholder="___('Tell us what is on your mind…')" required />

                    {{-- Honeypot anti-spam --}}
                    <input type="text" name="website" class="mk-hp" tabindex="-1" autocomplete="off" aria-hidden="true">

                    @if (function_exists('display_captcha'))
                        {!! display_captcha() !!}
                        @error('g-recaptcha-response')<p class="mk-alert mk-alert--error">{{ $message }}</p>@enderror
                    @endif

                    <div class="mk-form__foot">
                        <button type="submit" class="ui-btn ui-btn--lg">
                            <span>{{ ___('Send message') }}</span>
                            <x-ui.icon name="arrow-right" size="18" flip />
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </section>
@endsection
