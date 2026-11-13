{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  Product changelog (ChangelogController@index) on the marketing frame. Data:
  $entries (newest first, already product-filtered), $products (filter chips),
  $activeProduct, $base (/changelog; JSON and RSS live at $base.json / .rss),
  $brand.
--}}
@extends('marketing.layout')

@section('title', $brand.' — '.___('Changelog'))
@section('meta_description', ___('The latest product updates, improvements, and fixes for :brand.', ['brand' => $brand]))
@push('styles')
<link rel="alternate" type="application/rss+xml" title="{{ $brand }} changelog" href="{{ $base }}.rss">
@endpush

@section('content')
    @include('marketing.partials.page-hero', [
        'eyebrow' => $brand.' · '.___('Changelog'),
        'title' => ___("What's new"),
        'sub' => ___('Product updates, improvements, and fixes — newest first.'),
    ])

    <section class="mk-sec mk-changelog">
        <div class="mk-wrap mk-changelog__wrap">
            <div class="mk-changelog__bar">
                <ul class="mk-chips mk-changelog__filters">
                    <li><a href="{{ $base }}" @class(['is-active' => ! $activeProduct]) @if (! $activeProduct) aria-current="page" @endif>{{ ___('All') }}</a></li>
                    @foreach ($products as $p)
                        <li><a href="{{ $base }}?product={{ urlencode($p) }}" @class(['is-active' => $activeProduct === $p]) @if ($activeProduct === $p) aria-current="page" @endif>{{ $p }}</a></li>
                    @endforeach
                </ul>
                <span class="mk-changelog__feeds">
                    <a href="{{ $base }}.rss">RSS</a>
                    <a href="{{ $base }}.json">JSON</a>
                </span>
            </div>

            @forelse ($entries as $e)
                <article class="mk-changelog__entry">
                    <p class="mk-changelog__meta">
                        @if (! empty($e['date']))<time datetime="{{ $e['date'] }}">{{ $e['date'] }}</time>@endif
                        @if (! empty($e['product']))<span class="mk-chip">{{ $e['product'] }}</span>@endif
                        @if (! empty($e['version']))<span>{{ $e['version'] }}</span>@endif
                        @if (! empty($e['breaking']))<span class="cl-badge">{{ ___('BREAKING') }}</span>@endif
                    </p>
                    <h2 class="mk-h3">{{ $e['title'] ?? '' }}</h2>
                    @if (! empty($e['body']))<p>{{ $e['body'] }}</p>@endif
                </article>
            @empty
                <div class="mk-empty">
                    <p>{{ ___('No changelog entries yet.') }}</p>
                </div>
            @endforelse
        </div>
    </section>
@endsection
