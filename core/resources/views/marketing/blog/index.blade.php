{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Blog list (BlogController@index / @category / @tag). $blogs is a
     paginator; $title is set on the category and tag pages. --}}
@extends('marketing.layout')

@section('title', ($title ?? ___('Restaurant tech blog')).' - '.seo_brand())
@section('meta_description', ___('Guides and ideas on QR menus, online ordering and growing your restaurant.'))
@if (request()->filled('search'))
    @section('robots', 'noindex,follow')
@endif

@push('structured_data')
{!! seo_breadcrumbs_jsonld(array_values(array_filter([
    ['name' => ___('Home'), 'url' => route('home')],
    isset($title) ? ['name' => ___('Blog'), 'url' => route('blog.index')] : ['name' => ___('Blog')],
    isset($title) ? ['name' => $title] : null,
]))) !!}
@endpush

@section('content')
    @include('marketing.partials.page-hero', [
        'title' => $title ?? ___('Stories from the kitchen'),
        'eyebrow' => ___('Blog'),
        'sub' => ___('Operations, marketing, and product updates for restaurant operators.'),
        'crumbs' => isset($title) ? [
            ['name' => ___('Home'), 'url' => route('home')],
            ['name' => ___('Blog'), 'url' => route('blog.index')],
            ['name' => $title],
        ] : null,
    ])

    <section class="mk-sec mk-blog">
        <div class="mk-wrap">
            <form class="mk-search" method="get" action="{{ route('blog.index') }}" role="search">
                <input type="search" name="search" class="ui-input" placeholder="{{ ___('Search articles…') }}" value="{{ request('search') }}" aria-label="{{ ___('Search articles…') }}">
                <button type="submit" class="ui-btn">
                    <x-ui.icon name="search" size="18" />
                    <span>{{ ___('Search') }}</span>
                </button>
            </form>

            @if ($blogs->count())
                <div class="mk-blog__grid">
                    @foreach ($blogs as $blog)
                        @php
                            $mkUrl = route('blog.single', [$blog->id, $blog->slug]);
                            $mkCat = $blog->categories->first();
                        @endphp
                        <article class="mk-card mk-post-card">
                            @if ($blog->image)
                                <a href="{{ $mkUrl }}" class="mk-post-card__media" tabindex="-1" aria-hidden="true">
                                    <img src="{{ asset('storage/blog/'.$blog->image) }}" alt="" loading="lazy">
                                </a>
                            @endif
                            <div class="mk-post-card__body">
                                @if ($mkCat)
                                    <a class="mk-post-card__cat" href="{{ route('blog.category', $mkCat->slug) }}">{{ $mkCat->title }}</a>
                                @endif
                                <h2 class="mk-h3"><a href="{{ $mkUrl }}">{{ $blog->title }}</a></h2>
                                <p>{{ \Illuminate\Support\Str::limit(strip_tags($blog->meta_description ?? $blog->description ?? ''), 130) }}</p>
                                <div class="mk-post-card__meta">
                                    <span>{{ $blog->user->name ?? '' }}</span>
                                    <time datetime="{{ $blog->created_at?->toDateString() }}">{{ $blog->created_at?->format('M d, Y') }}</time>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>

                @if ($blogs->hasPages())
                    <div class="mk-blog__pager">{{ $blogs->links() }}</div>
                @endif
            @else
                <div class="mk-empty">
                    <h2>{{ ___('No articles yet') }}</h2>
                    <p>{{ ___('Check back soon for new stories.') }}</p>
                </div>
            @endif
        </div>
    </section>
@endsection
