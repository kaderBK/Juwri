{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('marketing.layout')

{{-- GloriaFood switch landing on the marketing frame (WI-16h; was a standalone
     page with its own inline styles). Same copy, CTA and FAQ JSON-LD. --}}
@section('title', ___('Switch from GloriaFood').' · '.(brand_context()->isReseller() ? seo_brand() : config('settings.site_title', config('app.name'))))

@section('meta_description', ___('GloriaFood is shutting down. Migrate your restaurant menu, customers and online ordering in minutes.'))

@push('structured_data')
@include('marketing.partials.jsonld', ['ld' => [
    '@context' => 'https://schema.org',
    '@type' => 'FAQPage',
    'mainEntity' => [[
        '@type' => 'Question',
        'name' => 'When is GloriaFood shutting down?',
        'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Oracle has set GloriaFood end-of-life for '.$eolDate.'.'],
    ]],
]])
@endpush

@section('content')
    @include('marketing.partials.page-hero', [
        'title' => ___('Move your restaurant off GloriaFood — in minutes'),
        'sub' => ___('GloriaFood is being discontinued by Oracle (end of life').' '.$eolDate.'). '.___('Bring your menu, customers and online ordering across, keep 0% commission, and get much more.'),
        'actions' => [['url' => $signupUrl, 'label' => ___('Start your free migration')]],
    ])

    <section class="mk-page-body">
        <div class="mk-wrap">
            <div class="mk-prose">
                <div class="ui-note" data-tone="warning">
                    <strong>{{ ___('Why now?') }}</strong>
                    {{ ___('After') }} {{ $eolDate }}, {{ ___('your GloriaFood menu, customers, delivery zones and order history become unreachable. Export early and import them here.') }}
                </div>

                <h2>{{ ___('What you get') }}</h2>
                <div class="ui-table-wrap">
                    <table class="ui-table">
                        <thead><tr><th>{{ ___('Feature') }}</th><th>{{ ___('Us') }}</th><th>GloriaFood</th></tr></thead>
                        <tbody>
                        @foreach ($features as $f)
                            <tr>
                                <td>{{ $f['us'] }}</td>
                                <td><x-ui.icon name="check" size="18" /><span class="ui-visually-hidden">{{ ___('Yes') }}</span></td>
                                <td>
                                    @if ($f['gf'] === true)
                                        <x-ui.icon name="check" size="18" /><span class="ui-visually-hidden">{{ ___('Yes') }}</span>
                                    @elseif ($f['gf'] === 'basic')
                                        <span class="ui-tag">{{ ___('basic') }}</span>
                                    @else
                                        <x-ui.icon name="x" size="18" /><span class="ui-visually-hidden">{{ ___('No') }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>

                <h2>{{ ___('Your migration checklist') }}</h2>
                <ol>
                    @foreach ($checklist as $step)
                        <li>{{ $step }}</li>
                    @endforeach
                </ol>
            </div>
            <div class="mk-actions">
                <a href="{{ $signupUrl }}" class="ui-btn">{{ ___('Start your free migration') }}</a>
            </div>
        </div>
    </section>
@endsection
