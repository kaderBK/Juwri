{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- One blog post (BlogController@single): $blog (tags already split into an
     array), $blogComments (approved top-level comments, paginated) and
     $blogHreflang (true-translation alternates, read by the layout head). --}}
@extends('marketing.layout')

@php
    $mkPostUrl = route('blog.single', [$blog->id, $blog->slug]);
    $mkDesc = $blog->meta_description ?: \Illuminate\Support\Str::limit(trim(strip_tags($blog->description)), 160);
    $mkImage = $blog->image ? asset('storage/blog/'.$blog->image) : null;
    $mkCats = $blog->categories ?? collect();
    $mkTags = \Juwri\Core\Support\ViewHelpers::trimmedNonEmpty(is_array($blog->tags) ? $blog->tags : []);
    $mkWords = count(preg_split('/\s+/u', trim(strip_tags((string) $blog->description)), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    $mkMinutes = max(1, (int) ceil($mkWords / 200));
@endphp

@section('title', $blog->title)

{{-- rev/seo R12: per-post snippet (admin field, fallback = first lines of body) --}}
@section('meta_description', $mkDesc)

{{-- rev/seo R5: id-only URLs (/blog/5) must canonicalize to the slugged form --}}
@section('canonical', $mkPostUrl)
{{-- rev/seo R19: $blogHreflang (passed by the controller) drives the head — see
     the layout's @isset guard. True-translation set only, empty for standalone. --}}

@if ($mkImage)
    @section('og_image', $mkImage)
@endif

@push('structured_data')
{!! seo_breadcrumbs_jsonld([
    ['name' => ___('Home'), 'url' => route('home')],
    ['name' => ___('Blog'), 'url' => route('blog.index')],
    ['name' => $blog->title],
]) !!}
{{-- rev/seo R12: BlogPosting with machine-readable dates + author + image --}}
@include('marketing.partials.jsonld', ['ld' => array_filter([
    '@context' => 'https://schema.org',
    '@type' => 'BlogPosting',
    'headline' => $blog->title,
    'description' => $mkDesc,
    'image' => $mkImage,
    'datePublished' => $blog->created_at?->toIso8601String(),
    'dateModified' => $blog->updated_at?->toIso8601String(),
    'author' => ! empty($blog->user->name) ? ['@type' => 'Person', 'name' => $blog->user->name] : ['@type' => 'Organization', 'name' => seo_brand()],
    'publisher' => ['@type' => 'Organization', 'name' => seo_brand()],
    'mainEntityOfPage' => $mkPostUrl,
])])
@endpush

@section('content')
    <article class="mk-post">
        <header class="mk-page-hero mk-post__hero">
            <div class="mk-wrap">
                <nav class="mk-crumbs" aria-label="{{ ___('Breadcrumbs') }}">
                    <ol>
                        <li><a href="{{ route('home') }}">{{ ___('Home') }}</a></li>
                        <li><a href="{{ route('blog.index') }}">{{ ___('Blog') }}</a></li>
                        @if ($mkCats->first())
                            <li><a href="{{ route('blog.category', $mkCats->first()->slug) }}">{{ $mkCats->first()->title }}</a></li>
                        @endif
                    </ol>
                </nav>
                <h1 class="mk-h1">{{ $blog->title }}</h1>
                <p class="mk-post__meta">
                    @if (! empty($blog->user->name))
                        <span>{{ $blog->user->name }}</span>
                        <span aria-hidden="true">·</span>
                    @endif
                    <time datetime="{{ $blog->created_at?->toDateString() }}">{{ $blog->created_at?->format('M d, Y') }}</time>
                    <span aria-hidden="true">·</span>
                    <span>{{ ___(':count min read', ['count' => $mkMinutes]) }}</span>
                </p>
            </div>
        </header>

        @if ($mkImage)
            <div class="mk-wrap">
                <figure class="mk-post__figure">
                    <img src="{{ $mkImage }}" alt="{{ $blog->title }}">
                </figure>
            </div>
        @endif

        <div class="mk-sec mk-post__sec">
            <div class="mk-wrap mk-side">
                <div class="mk-post__main">
                    <div class="mk-prose">
                        @clean($blog->description, 'richtext')
                    </div>

                    @if (count($mkTags))
                        <ul class="mk-chips mk-post__tags" aria-label="{{ ___('Tags') }}">
                            @foreach ($mkTags as $mkTag)
                                <li><a href="{{ route('blog.tag', $mkTag) }}">#{{ $mkTag }}</a></li>
                            @endforeach
                        </ul>
                    @endif

                    @if (config('settings.blog_comment_enable'))
                        <section class="mk-comments" id="comments" aria-labelledby="mk-comments-title">
                            <h2 class="mk-h3" id="mk-comments-title">{{ ___('Comments') }} ({{ $blogComments->total() }})</h2>

                            @if (session('app_alert_message'))
                                <p @class(['mk-alert', 'mk-alert--error' => session('app_alert_type') === 'error']) role="status">{{ session('app_alert_message') }}</p>
                            @endif

                            @foreach ($blogComments as $comment)
                                @include('marketing.blog.comment', ['comment' => $comment])
                            @endforeach

                            @if ($blogComments->hasPages())
                                <div class="mk-blog__pager">{{ $blogComments->links() }}</div>
                            @endif

                            <form class="mk-form mk-comments__form" action="{{ route('blog.comment', [$blog->id, $blog->slug]) }}" method="post">
                                @csrf
                                <h3 class="mk-h3">{{ ___('Leave a comment') }}</h3>
                                @guest
                                    <div class="mk-form__row">
                                        <x-ui.field name="user_name" id="comment-name" :label="___('Name')" required autocomplete="name" />
                                        <x-ui.field name="user_email" id="comment-email" type="email" :label="___('Email')" required autocomplete="email" />
                                    </div>
                                @endguest
                                <x-ui.field name="comment" id="comment-body" type="textarea" :rows="4" :label="___('Comment')" required />
                                <input type="hidden" name="comment_parent" value="0">
                                @if (function_exists('display_captcha'))
                                    {!! display_captcha() !!}
                                @endif
                                <button type="submit" class="ui-btn">{{ ___('Post comment') }}</button>
                            </form>
                        </section>
                    @endif
                </div>

                <div class="mk-side__aside">
                    @include('marketing.blog.sidebar')
                </div>
            </div>
        </div>
    </article>
@endsection
