{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('marketing.layout')

@section('title', ___('AI Menu Scanner — Turn a Photo of Your Menu into a Digital One') . ' | ' . seo_brand())
@section('meta_description', ___('Snap a photo of your paper menu and our AI turns it into a digital QR menu with items, prices and translations — see a sample, then scan your own free.'))
@section('canonical', route('tools.menu-scanner'))

@push('structured_data')
{!! seo_breadcrumbs_jsonld([
    ['name' => ___('Home'), 'url' => route('home')],
    ['name' => ___('AI Menu Scanner')],
]) !!}
@endpush

@php
    /* TEASER ONLY: nothing is uploaded, read or stored. The "before" is a drawn
       paper-menu mock and the "after" a kit mock of the digital menu with the
       same dishes — never a mislabeled screenshot. */
    $msDishes = [
        ['Margherita', '$9', 'flame'],
        ['Pepperoni', '$11', 'flame'],
        ['Caesar Salad', '$7', 'dine-in'],
        ['Lemonade', '$3', 'sparkle'],
        ['Tiramisu', '$6', 'gift'],
    ];
@endphp

@section('content')
    @include('marketing.partials.page-hero', [
        'eyebrow' => ___('AI Menu Studio'),
        'title' => ___('AI Menu Scanner'),
        'sub' => ___('Photograph your paper menu and watch AI turn it into a digital ordering menu — items, prices and photos, ready in minutes.'),
        'crumbs' => [
            ['name' => ___('Home'), 'url' => route('home')],
            ['name' => ___('AI Menu Scanner')],
        ],
    ])

    <section class="mk-sec">
        <div class="mk-wrap">
            <div class="mk-ms" data-ms-root>
                {{-- Drop zone (teaser: nothing is uploaded or stored) --}}
                <button type="button" class="mk-ms__drop" data-ms-trigger aria-controls="ms-sample" aria-expanded="false">
                    <span class="mk-ico"><x-ui.icon name="upload" size="22" /></span>
                    <b>{{ ___('Drop a photo of your menu') }}</b>
                    <span>{{ ___('or tap to see a sample') }}</span>
                    <span class="mk-chip">{{ ___('Try a sample') }}</span>
                </button>

                {{-- Canned before -> after sample (revealed on interaction) --}}
                <div class="mk-ms__sample" id="ms-sample" data-ms-sample hidden>
                    <div class="mk-ms__pair">
                        <figure class="mk-ms__col">
                            <div class="mk-ms__paper" aria-hidden="true">
                                <span class="mk-ms__paper-title">MENU</span>
                                @foreach ($msDishes as $i => [$dish, $price])
                                    <span @class(['mk-ms__paper-row', 'mk-ms__paper-row--faint' => $loop->last])><i>{{ $dish }}</i><b>{{ usd_ltr_inline($price) }}</b></span>
                                @endforeach
                            </div>
                            <figcaption>{{ ___('Your paper menu') }}</figcaption>
                        </figure>

                        <div class="mk-ms__arrow" aria-hidden="true"><x-ui.icon name="arrow-right" size="28" flip /></div>

                        <figure class="mk-ms__col">
                            <div class="mk-panel mk-ms__digital" role="img" aria-label="{{ ___('Your digital menu') }}">
                                <div class="mk-panel__head"><x-ui.icon name="qr" size="18" /> <span>MENU</span></div>
                                @foreach ($msDishes as [$dish, $price, $icon])
                                    <div class="mk-panel__row mk-ms__dish">
                                        <span class="mk-ms__thumb"><x-ui.icon :name="$icon" size="18" /></span>
                                        <span>{{ $dish }}</span>
                                        <b>{{ usd_ltr_inline($price) }}</b>
                                    </div>
                                @endforeach
                            </div>
                            <figcaption>{{ ___('Your digital menu') }}</figcaption>
                        </figure>
                    </div>
                    <p class="mk-ms__note" tabindex="-1" data-ms-focus>{{ ___('Preview only — create your free account to scan your own menu.') }}</p>
                </div>

                <div class="mk-actions">
                    <a href="{{ route('register') }}" class="ui-btn">{{ ___('Create your free menu') }}</a>
                    @if (Route::has('studio'))
                        <a href="{{ route('studio') }}" class="ui-btn ui-btn--secondary">{{ ___('See it in the AI Menu Studio') }}</a>
                    @endif
                </div>
            </div>
        </div>
    </section>

    <section class="mk-sec mk-sec--tint">
        <div class="mk-wrap">
            <div class="mk-sec__head">
                <span class="mk-eyebrow">{{ ___('How it works') }}</span>
                <h2 class="mk-h2">{{ ___('From paper to digital in minutes') }}</h2>
            </div>
            <ol class="mk-steps">
                @foreach ([
                    ___('Snap or upload a photo of your printed menu.'),
                    ___('AI reads every item, price and section — in your language.'),
                    ___('Review, tweak and publish your digital menu in minutes.'),
                ] as $i => $step)
                    <li class="mk-step">
                        <span class="mk-step__num">{{ $i + 1 }}</span>
                        <p>{{ $step }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>
@endsection

@push('scripts')
<script src="{{ asset('assets/owner-ui/tools/menu-scanner.js') }}?v={{ config('appinfo.version', '1') }}" defer></script>
@endpush
