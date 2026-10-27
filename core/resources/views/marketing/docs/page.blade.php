{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  Owner Help Center page (HelpCenterController::landingShell) on the marketing
  frame. $bodyHtml is already sanitised HTML from HelpCenterController::render()
  (it carries its own <h1>); $navGroups feeds the grouped guide list,
  $widgetHtml is the was-this-helpful widget (article pages only), $searchAction
  the search endpoint, $indexUrl the Help Center home, $activeSlug the open guide.
--}}
@extends('marketing.layout')

@php
    $mkDocName = trim(preg_replace('/\s+—\s+.*$/u', '', $docTitle));
@endphp

@section('title', $docTitle)
@section('meta_description', ___('Guides and help for :brand — set up your menu, take orders, and run your restaurant.', ['brand' => $brand]))

@push('structured_data')
{!! seo_breadcrumbs_jsonld(array_values(array_filter([
    ['name' => ___('Help Center'), 'url' => $indexUrl],
    $activeSlug ? ['name' => $mkDocName] : null,
]))) !!}
@endpush

@if ($activeSlug)
    @push('scripts')
        <script src="{{ asset('assets/owner-ui/site-docs.js') }}?v={{ config('appinfo.version', '1') }}" defer></script>
    @endpush
@endif

@section('content')
    <section class="mk-page-hero mk-docs-hero">
        <div class="mk-wrap">
            <nav class="mk-crumbs" aria-label="{{ ___('Breadcrumbs') }}">
                <ol>
                    <li>
                        @if ($activeSlug)
                            <a href="{{ $indexUrl }}">{{ ___('Help Center') }}</a>
                        @else
                            <span aria-current="page">{{ ___('Help Center') }}</span>
                        @endif
                    </li>
                    @if ($activeSlug)
                        <li><span aria-current="page">{{ $mkDocName }}</span></li>
                    @endif
                </ol>
            </nav>
            <span class="mk-eyebrow">{{ $brand }} · {{ ___('Help Center') }}</span>
            <p class="mk-docs-hero__title">{{ ___('Guides & help') }}</p>
            <form class="mk-search" method="get" action="{{ $searchAction }}" role="search">
                <input type="search" name="q" class="ui-input" value="{{ request('q') }}" placeholder="{{ ___('Search the guides…') }}" aria-label="{{ ___('Search help') }}">
                <button type="submit" class="ui-btn">
                    <x-ui.icon name="search" size="18" />
                    <span>{{ ___('Search') }}</span>
                </button>
            </form>
        </div>
    </section>

    <div class="mk-sec mk-docs">
        <div @class(['mk-wrap', 'mk-side', 'mk-side--start', 'mk-docs__grid', 'is-article' => $activeSlug])>
            <aside class="mk-side__aside mk-docs__aside">
                @if ($activeSlug)
                    {{-- "On this page": filled by site-docs.js from the article headings (stays hidden under two). --}}
                    <nav id="docToc" class="mk-docs__toc" aria-label="{{ ___('On this page') }}" data-label="{{ ___('On this page') }}" hidden></nav>
                    {{-- Phones: the guide list folds behind this bar so the article shows first. --}}
                    <input type="checkbox" id="docnav" class="mk-docs__toggle" hidden>
                    <label for="docnav" class="mk-docs__bar">
                        <span>{{ ___('All guides') }}</span>
                        <x-ui.icon name="chevron-down" size="18" />
                    </label>
                @endif
                <nav class="mk-docs__nav" aria-label="{{ ___('All guides') }}">
                    <a class="mk-docs__all" href="{{ $indexUrl }}">{{ ___('All guides') }}</a>
                    @foreach ($navGroups as $group)
                        <p class="mk-docs__group">{{ ___($group['group']) }}</p>
                        <ul>
                            @foreach ($group['items'] as $item)
                                <li><a href="{{ $item['url'] }}" @if ($item['active']) class="is-active" aria-current="page" @endif>{{ $item['title'] }}</a></li>
                            @endforeach
                        </ul>
                    @endforeach
                </nav>
            </aside>

            <article class="mk-prose mk-docs__body">
                {!! $bodyHtml !!}
                {!! $widgetHtml !!}
            </article>
        </div>
    </div>
@endsection
