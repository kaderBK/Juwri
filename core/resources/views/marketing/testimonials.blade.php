{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('marketing.layout')

@section('title', ___('What restaurant owners say') . ' - ' . seo_brand())
@section('meta_description', ___('Real stories from restaurants using QR menus and online ordering to serve more customers.'))

@push('structured_data')
{!! seo_breadcrumbs_jsonld([
    ['name' => ___('Home'), 'url' => route('home')],
    ['name' => ___('Testimonials')],
]) !!}
@endpush

@section('content')
    @include('marketing.partials.page-hero', [
        'title' => ___('What restaurants say'),
        'eyebrow' => ___('Testimonials'),
        'sub' => ___('Stories from owners who switched to a digital menu.'),
    ])

    <section class="mk-sec">
        <div class="mk-wrap">
            @if (! empty($testimonials) && $testimonials->count())
                <div class="mk-quotes">
                    @foreach ($testimonials as $t)
                        @php
                            $tTr = $t->translations->{get_lang()} ?? null;
                            $tName = ! empty($tTr->name) ? $tTr->name : $t->name;
                            $tRole = ! empty($tTr->designation) ? $tTr->designation : ($t->designation ?? '');
                            $tBody = ! empty($tTr->content) ? $tTr->content : ($t->content ?? $t->message ?? '');
                            $tImg = (! empty($t->image) && $t->image !== 'default_user.png') ? asset('storage/testimonials/'.$t->image) : null;
                        @endphp
                        <figure class="mk-quote">
                            <span class="mk-stars" role="img" aria-label="5/5">★★★★★</span>
                            <blockquote>“{{ Str::limit(strip_tags($tBody), 190) }}”</blockquote>
                            <figcaption>
                                @if ($tImg)
                                    <img src="{{ $tImg }}" alt="" width="40" height="40" loading="lazy" decoding="async">
                                @else
                                    <span class="mk-quote__avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr((string) $tName, 0, 1)) }}</span>
                                @endif
                                <span>
                                    <strong>{{ $tName }}</strong>
                                    @if (! empty($tRole)){{ $tRole }}@endif
                                </span>
                            </figcaption>
                        </figure>
                    @endforeach
                </div>
                @if (method_exists($testimonials, 'hasPages') && $testimonials->hasPages())
                    <div class="mk-pager">{{ $testimonials->links() }}</div>
                @endif
            @else
                <div class="mk-empty">
                    <h2>{{ ___('No testimonials yet') }}</h2>
                    <p>{{ ___('Be the first to share your experience.') }}</p>
                </div>
            @endif
        </div>
    </section>

    <section class="mk-sec">
        <div class="mk-wrap">
            <div class="mk-band">
                <div>
                    <h2 class="mk-h2">{{ ___('Share your story') }}</h2>
                    <p>{{ ___('Submit a testimonial - we love hearing how restaurants use us.') }}</p>
                </div>
                @if (Route::has('feedback'))
                    <div class="mk-actions">
                        <a href="{{ route('feedback') }}" class="ui-btn">
                            <x-ui.icon name="sparkle" size="18" />
                            <span>{{ ___('Submit feedback') }}</span>
                        </a>
                    </div>
                @endif
            </div>
        </div>
    </section>
@endsection
