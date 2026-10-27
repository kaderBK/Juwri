{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@if (! empty($blogArticles) && $blogArticles->count() && Route::has('blog.single'))
    <section class="mk-sec">
        <div class="mk-wrap">
            <div class="mk-sec__head">
                <h2 class="mk-h2">{{ ___('Insights, updates, and company news') }}</h2>
            </div>
            <div class="mk-cards">
                @foreach ($blogArticles as $mkPost)
                    <article class="mk-card">
                        <span class="mk-card__meta">{{ date_formating((string) $mkPost->created_at, 'M d, Y') }}</span>
                        <h3><a href="{{ route('blog.single', [$mkPost->id, $mkPost->slug]) }}">{{ $mkPost->title }}</a></h3>
                        <p>{{ \Illuminate\Support\Str::limit(strip_tags($mkPost->meta_description ?? $mkPost->details ?? ''), 110) }}</p>
                        <div class="mk-card__links"><a href="{{ route('blog.single', [$mkPost->id, $mkPost->slug]) }}">{{ ___('Read More') }}</a></div>
                    </article>
                @endforeach
            </div>
            @if (Route::has('blog.index'))
                <div class="mk-actions"><a href="{{ route('blog.index') }}" class="ui-btn ui-btn--secondary">{{ ___('See all posts') }}</a></div>
            @endif
        </div>
    </section>
@endif
