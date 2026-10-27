{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Blog sidebar: categories, recent posts and tags. Data comes from the
     'marketing.blog.sidebar' view composer (ViewServiceProvider):
     $blogCategories (with blogs_count), $recentBlogs, $blogTags. --}}
@php
    $mkCats = $blogCategories ?? collect();
    $mkRecent = $recentBlogs ?? collect();
    $mkTags = \Juwri\Core\Support\ViewHelpers::trimmedNonEmpty((array) ($blogTags ?? []));
    $mkTags = array_values(array_unique($mkTags));
@endphp
<aside class="mk-blog-side" aria-label="{{ ___('Blog') }}">
    @if ($mkCats->count())
        <section class="mk-blog-side__box">
            <h2 class="mk-blog-side__title">{{ ___('Categories') }}</h2>
            <ul class="mk-blog-side__list">
                @foreach ($mkCats as $mkCat)
                    <li>
                        <a href="{{ route('blog.category', $mkCat->slug) }}">
                            <span>{{ $mkCat->title }}</span>
                            @isset($mkCat->blogs_count)<span class="mk-blog-side__count">{{ $mkCat->blogs_count }}</span>@endisset
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($mkRecent->count())
        <section class="mk-blog-side__box">
            <h2 class="mk-blog-side__title">{{ ___('Recent posts') }}</h2>
            <ul class="mk-blog-side__list">
                @foreach ($mkRecent as $mkPost)
                    <li>
                        <a href="{{ route('blog.single', [$mkPost->id, $mkPost->slug]) }}">
                            <span>{{ $mkPost->title }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if (count($mkTags))
        <section class="mk-blog-side__box">
            <h2 class="mk-blog-side__title">{{ ___('Tags') }}</h2>
            <ul class="mk-chips">
                @foreach (array_slice($mkTags, 0, 30) as $mkTag)
                    <li><a href="{{ route('blog.tag', $mkTag) }}">#{{ $mkTag }}</a></li>
                @endforeach
            </ul>
        </section>
    @endif
</aside>
