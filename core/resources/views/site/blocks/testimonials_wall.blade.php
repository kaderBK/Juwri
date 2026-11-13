{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- Site block: testimonials_wall. Testimonial rows (settings.limit, default
     10) as a static wrapped wall of quote cards, with the rating summary.
     Names, roles and quotes use the current-locale translation when present.
     Optional heading override. --}}
@php
    $sbLimit = (int) ($block['settings']['limit'] ?? 10) ?: 10;
    try {
        $testimonials = \Juwri\Modules\Content\Models\Testimonial::limit($sbLimit)->get();
    } catch (\Throwable $e) {
        $testimonials = collect();
    }
    $sbHeading = (string) ($block['content']['heading'] ?? '');
@endphp
@if ($testimonials->count())
    <section class="mk-sec sb-block" data-site-block="testimonials_wall">
        <div class="mk-wrap">
            <div class="mk-sec__head">
                <h2 class="mk-h2">{{ $sbHeading !== '' ? $sbHeading : ___('What restaurants say about us.') }}</h2>
                <p class="mk-lead sb-rating">
                    <span class="mk-stars" aria-hidden="true">★★★★★</span>
                    <strong>4.9/5</strong>
                    <span>{{ ___('Loved by restaurants worldwide') }}</span>
                </p>
            </div>
            <div class="sb-wall">
                @foreach ($testimonials as $sbT)
                    @php
                        $sbName = (string) \Juwri\Core\Support\Views\PublicSiteViewHelpers::testimonialLocalizedField($sbT, 'name', $sbT->name);
                        $sbRole = (string) \Juwri\Core\Support\Views\PublicSiteViewHelpers::testimonialLocalizedField($sbT, 'designation', $sbT->designation ?? '');
                        $sbBody = (string) \Juwri\Core\Support\Views\PublicSiteViewHelpers::testimonialLocalizedField($sbT, 'content', $sbT->content ?? $sbT->message ?? '');
                    @endphp
                    <figure class="mk-quote">
                        <span class="mk-stars" aria-hidden="true">★★★★★</span>
                        <blockquote>“{{ \Illuminate\Support\Str::limit(strip_tags($sbBody), 190) }}”</blockquote>
                        <figcaption>
                            <span class="sb-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr(trim($sbName), 0, 1)) }}</span>
                            <span><strong>{{ $sbName }}</strong>@if ($sbRole !== ''){{ $sbRole }}@endif</span>
                        </figcaption>
                    </figure>
                @endforeach
            </div>
        </div>
    </section>
@endif
