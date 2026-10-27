{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Site block: blog_teasers. Latest published posts (settings.limit, default
     3) as kit cards; optional heading override. --}}
@php
    $sbLimit = (int) ($block['settings']['limit'] ?? 3) ?: 3;
    try {
        $blogArticles = \Juwri\Modules\Content\Models\Blog::with('user')->where('status', 'publish')->limit($sbLimit)->get();
    } catch (\Throwable $e) {
        $blogArticles = collect();
    }
    $sbHeading = (string) ($block['content']['heading'] ?? '');
@endphp
@if ($blogArticles->count() && Route::has('blog.single'))
    <section class="mk-sec sb-block" data-site-block="blog_teasers">
        <div class="mk-wrap">
            <div class="mk-sec__head">
                <h2 class="mk-h2">{{ $sbHeading !== '' ? $sbHeading : ___('Insights, updates, and company news') }}</h2>
            </div>
            <div class="mk-cards">
                @foreach ($blogArticles as $sbPost)
                    <article class="mk-card">
                        <span class="mk-card__meta">{{ date_formating((string) $sbPost->created_at, 'M d, Y') }}</span>
                        <h3><a href="{{ route('blog.single', [$sbPost->id, $sbPost->slug]) }}">{{ $sbPost->title }}</a></h3>
                        <p>{{ \Illuminate\Support\Str::limit(strip_tags($sbPost->meta_description ?? $sbPost->details ?? ''), 110) }}</p>
                        <div class="mk-card__links"><a href="{{ route('blog.single', [$sbPost->id, $sbPost->slug]) }}">{{ ___('Read More') }}</a></div>
                    </article>
                @endforeach
            </div>
            @if (Route::has('blog.index'))
                <div class="mk-actions"><a href="{{ route('blog.index') }}" class="ui-btn ui-btn--secondary">{{ ___('See all posts') }}</a></div>
            @endif
        </div>
    </section>
@endif
