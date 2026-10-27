{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('store_templates.layout')

@php
    $dir = in_array(app()->getLocale(), ['ar', 'fa', 'ur', 'he'], true) ? 'rtl' : 'ltr';
    /* rev/multi-vertical Lane C (C5) — vocab sweep. Every OTHER shared view is
       either food-only (the 17 modern themes' own partials) or never renders
       for a non-food store (shelf/bookla are self-contained, per-theme
       layouts). This info page is the one exception: `infoPage()` always
       extends the shared `store_templates.layout`, for EVERY theme, so a
       retail/service store's Terms/Privacy page used to say "Back to menu"
       verbatim. A food/restaurant store keeps the exact original string —
       byte-identical, per DECISIONS "restaurants unaffected". */
    // Deliberately 'Back to :label', not 'Back to :catalog' — the latter's
    // slug ('back_to_catalog') collides with an unrelated pre-existing lang
    // key ('Back to catalog', no placeholder), which would silently swallow
    // the vocab word and always show "catalog" even for a 'services' store.
    $ipBackLabel = $store->isFoodVertical()
        ? ___('Back to menu')
        : ___('Back to :label', ['label' => app(\App\Services\Verticals\VerticalVocab::class)->forStore($store, 'catalog')]);
@endphp

@push('style_at_top')
    <link rel="stylesheet" href="{{ asset('assets/store_templates/'.$theme.'/css/style.css?ver='.config('appinfo.version').'.'.@filemtime(public_path('assets/store_templates/'.$theme.'/css/style.css'))) }}">
    <style nonce="{{ csp_nonce() }}">
        .ip { max-width: 760px; margin: 0 auto; padding: 16px 16px 90px; }
        .ip-top { display: flex; align-items: center; gap: 14px; padding: 6px 0 16px; }
        .ip-back { display: inline-flex; align-items: center; gap: 6px; text-decoration: none; color: inherit; font-weight: 700; font-size: 14px; }
        .ip-back svg { width: 18px; height: 18px; flex: none; }
        [dir="rtl"] .ip-back svg { transform: scaleX(-1); }
        .ip-store { margin-inline-start: auto; font-weight: 700; opacity: .85; }
        .ip h1 { font-size: 25px; margin: 0 0 4px; color: inherit; }
        .ip-rule { width: 46px; height: 4px; border-radius: 4px; background: currentColor; opacity: .5; margin: 12px 0 22px; }
        .ip-body {
            font-size: 15.5px; line-height: 1.65; color: inherit;
            border: 1px solid color-mix(in srgb, currentColor 16%, transparent);
            border-radius: 16px; padding: 20px 18px;
            background: color-mix(in srgb, currentColor 3%, transparent);
            white-space: normal; overflow-wrap: anywhere;
        }
        .ip-tabs { display: flex; flex-wrap: wrap; gap: 8px; margin: 22px 0 0; }
        .ip-tab { text-decoration: none; color: inherit; font-size: 13px; font-weight: 700; padding: 8px 14px; border-radius: 999px; border: 1px solid color-mix(in srgb, currentColor 24%, transparent); }
        .ip-tab.is-current { background: color-mix(in srgb, currentColor 12%, transparent); border-color: currentColor; }
    </style>
@endpush

@section('content')
<div class="{{ $rootClass }}" id="{{ $theme }}">
    <main class="ip" dir="{{ $dir }}">
        <div class="ip-top">
            <a class="ip-back" href="{{ $backUrl }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
                {{ $ipBackLabel }}
            </a>
            <span class="ip-store">{{ $store->title }}</span>
        </div>

        <h1>{{ $pageTitle }}</h1>
        <div class="ip-rule"></div>
        <div class="ip-body">{!! nl2br(e($pageBody)) !!}</div>

        {{-- google-suite Lane D — keyless store map. Self-gated on the
             store_map_embed toggle + available coords/address; renders nothing
             otherwise. --}}
        @include('store_templates.partials.store-map')

        @if(count($infoPages) > 1)
            <div class="ip-tabs">
                @foreach($infoPages as $slug => $label)
                    <a class="ip-tab {{ $slug === $pageSlug ? 'is-current' : '' }}" href="{{ storefront_localized_link(route('store.infoPage', [$store->id, $slug])) }}">{{ $label }}</a>
                @endforeach
            </div>
        @endif
    </main>
</div>
@endsection
