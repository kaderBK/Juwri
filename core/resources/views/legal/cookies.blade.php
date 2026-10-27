{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    P1-23: the cookie list (privacy.md §5 "A full list of the cookies we use … is at /cookies", FILL-LIST item 16).
    Printed from Juwri\Core\Legal\CookieRegistry — the same list the banner (partials/cookie-consent) and the server
    consent gate (CookieConsent) read, so the page cannot drift from what the code sets. Every install shows it; the
    JUWRI LTD sentence + Privacy Policy link only where this instance publishes JUWRI's legal pages ($juwri).
--}}
@extends('marketing.layout')

@section('title', __('cookies.title').' - '.brand_title())
@section('meta_description', __('cookies.intro'))
@section('canonical', url()->current())

@section('content')
<section class="mk-page-hero mk-legal__hero">
    <div class="mk-wrap">
        <h1 class="mk-h1">{{ __('cookies.title') }}</h1>
    </div>
</section>

<section class="mk-page-body mk-legal">
    <div class="mk-wrap">
        <div class="mk-prose" data-cookie-list>
            <p data-legal-updated>{{ __('site.legal.updated', ['date' => $updated]) }}</p>
            <p>{{ __('cookies.intro') }}</p>
            @if ($juwri)
                <p data-cookie-company>{{ \Juwri\Core\Legal\LegalText::inline('cookies.intro_juwri') }}</p>
            @endif
            <p>{{ __('cookies.consent_note') }}</p>
            <p><button type="button" class="ui-btn ui-btn--secondary" data-cookie-settings>{{ __('site.cookie.settings') }}</button></p>

            @foreach ($groups as $ckCategory => $ckEntries)
                <h2 id="{{ $ckCategory }}">{{ __('cookies.category.'.$ckCategory) }}</h2>
                <p>{{ __('cookies.category_note.'.$ckCategory) }}</p>
                <table data-cookie-category="{{ $ckCategory }}">
                    <thead>
                        <tr>
                            <th scope="col">{{ __('cookies.col.name') }}</th>
                            <th scope="col">{{ __('cookies.col.purpose') }}</th>
                            <th scope="col">{{ __('cookies.col.lifetime') }}</th>
                            <th scope="col">{{ __('cookies.col.party') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($ckEntries as $ckKey => $ck)
                            <tr data-cookie="{{ $ckKey }}">
                                <td><code dir="ltr">{{ $ck['name'] }}</code></td>
                                <td>{{ __('cookies.purpose.'.$ck['purpose']) }}</td>
                                <td>{{ \Juwri\Core\Legal\CookieRegistry::lifetime($ck['minutes']) }}</td>
                                <td>{{ $ck['party'] === 'third' ? __('cookies.party.third', ['provider' => $ck['provider'] ?? '']) : __('cookies.party.first') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endforeach

            <p>{{ __('cookies.stores') }}</p>
        </div>

        @if ($documents !== [])
            <nav class="mk-legal__nav" aria-label="{{ __('site.footer.legal') }}">
                <h2 class="mk-legal__nav-title">{{ __('site.footer.legal') }}</h2>
                <ul>
                    @foreach ($documents as $lgKey => $lgDoc)
                        <li><a href="{{ \Juwri\Core\Legal\LegalDocuments::url($lgKey) }}">{{ __($lgDoc['label']) }}</a></li>
                    @endforeach
                    <li><a href="{{ route('legal.cookies') }}" aria-current="page">{{ __('cookies.title') }}</a></li>
                </ul>
            </nav>
        @endif
    </div>
</section>
@endsection
