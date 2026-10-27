{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('marketing.layout')

{{-- I1 (SEO run): theme gallery. Title verbatim from KEYWORDS §Themes.
     Thumbnails come from theme_showcase_shot() (core/app helper: per-locale
     capture with a cache-bust, null when none exists — that theme is skipped). --}}
@section('title', ___('Restaurant Menu Themes & Website Templates — 19 Designs, Live Demos').' - '.seo_brand())
@section('meta_description', ___('Browse 19 restaurant menu themes and website templates with live demos. Every design is mobile-first, RTL-ready and wired to the same QR ordering, WhatsApp and POS engine — no code required.'))
@section('canonical', route('themes'))

@push('structured_data')
{!! seo_breadcrumbs_jsonld([
    ['name' => ___('Home'), 'url' => route('home')],
    ['name' => ___('Themes')],
]) !!}
@endpush

@php
    /* slug => display name. A keyed MAP on purpose (per-theme DATA, not a copy of
       the template keep-list) — KeptTemplateRegistryTest exempts maps and flags
       list-context slug runs. Theme names are product names — deliberately not
       translated. */
    $psThemes = [
        'crave' => 'Crave', 'vivid' => 'Vivid', 'ember' => 'Ember', 'matcha' => 'Matcha',
        'soiree' => 'Soirée', 'scoop' => 'Scoop', 'orbit' => 'Orbit', 'folio' => 'Folio',
        'lime' => 'Lime', 'velora' => 'Velora', 'dough' => 'Dough', 'aurora' => 'Aurora',
        'crumb' => 'Crumb', 'feast' => 'Feast', 'glow' => 'Glow', 'picnic' => 'Picnic',
        'plate' => 'Plate', 'velvet' => 'Velvet',
    ];

    /* $demoStores = the slugs of the demo storefronts that actually exist
       (ThemeShowcaseSeeder publishes one at "{slug}-demo"), from the controller:
       an unseeded environment degrades to a Theme-Store link, never a 404. */
    $demoStores = $demoStores ?? [];
    $demoFallback = Route::has('marketplace.index') ? route('marketplace.index') : route('register');

    $thActions = [['url' => route('register'), 'label' => ___('Start free')]];
    if (Route::has('marketplace.index')) {
        $thActions[] = ['url' => route('marketplace.index'), 'label' => ___('Browse the theme store'), 'ghost' => true];
    }
@endphp

@section('content')
    @include('marketing.partials.page-hero', [
        'eyebrow' => ___('Themes'),
        'title' => ___('Restaurant menu themes & website templates'),
        'sub' => ___('Pick a design, publish in minutes, and restyle it whenever you like. Every theme is mobile-first, RTL-ready, and takes real orders from the first scan.'),
        'actions' => $thActions,
    ])

    {{-- Gallery grid --}}
    <section class="mk-sec mk-sec--tint">
        <div class="mk-wrap">
            <div class="mk-sec__head">
                <span class="mk-eyebrow">{{ ___('Make it yours') }}</span>
                <h2 class="mk-h2">{{ ___('19 themes. Zero code.') }}</h2>
                <p class="mk-lead">{{ ___('Every theme is wired to the same live ordering engine.') }}</p>
            </div>

            <ul class="th-grid">
                @foreach ($psThemes as $tSlug => $tName)
                    @php
                        $shot = theme_showcase_shot($tSlug);
                        $hasDemo = in_array($tSlug.'-demo', $demoStores, true);
                        $href = $hasDemo ? url('/'.$tSlug.'-demo') : $demoFallback;
                    @endphp
                    @if ($shot)
                        <li>
                            <a class="th-card" href="{{ $href }}"
                               @if ($hasDemo) target="_blank" rel="noopener" @endif
                               aria-label="{{ $tName }} — {{ ___('View live demo') }}">
                                <span class="th-card__phone">
                                    <img src="{{ $shot }}" alt="{{ $tName }}" loading="lazy" decoding="async" width="480" height="987">
                                </span>
                                <span class="th-card__meta">
                                    <span class="th-card__name">{{ $tName }}</span>
                                    @if ($hasDemo)
                                        <span class="th-card__demo">{{ ___('View live demo') }} <x-ui.icon name="external" size="14" /></span>
                                    @endif
                                </span>
                            </a>
                        </li>
                    @endif
                @endforeach
            </ul>
        </div>
    </section>

    {{-- Closing --}}
    <section class="mk-sec">
        <div class="mk-wrap">
            <div class="mk-cta">
                <h2 class="mk-h2">{{ ___('Every theme runs the full platform') }}</h2>
                <p>{{ ___('No code, no add-ons — switch designs whenever you like and keep every order, gateway and feature.') }}</p>
                <div class="mk-actions">
                    <a href="{{ route('register') }}" class="ui-btn">{{ ___('Start free') }}</a>
                    @if (Route::has('pricing'))
                        <a href="{{ route('pricing') }}" class="ui-btn ui-btn--ghost">{{ ___('See pricing') }}</a>
                    @endif
                </div>
            </div>
        </div>
    </section>
@endsection
