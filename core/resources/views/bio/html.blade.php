{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
    Advanced HTML (Builder.js) bio page — the minimal public chrome for a store whose
    bio page is authored as freeform HTML (posts.bio_builder = 'html'). Served by
    User\BioPublicController@show under the ENFORCING storefront CSP (AddCspHeaders,
    NO unsafe-eval): correct, because the published page is plain, allowlist-sanitized
    HTML with no Builder.js/eval at runtime.

    OUTPUT-TIME XSS CHOKE POINT: the builder HTML is ALWAYS re-sanitized through the
    allowlist DOM sanitizer at echo (idempotent — already cleaned on save/publish).
    NEVER echo builder HTML without Html::cleanPage().
--}}
@php
    $lang = str_replace('_', '-', app()->getLocale());
    $dir = ($rtl ?? is_rtl()) ? 'rtl' : 'ltr';
    $isPreview = $isPreview ?? false;
    $title = trim((string) ($store->bio_title ?: $store->title ?: config('app.name')));
@endphp
<!DOCTYPE html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    @if($isPreview)
        <meta name="robots" content="noindex,nofollow">
    @else
        <meta property="og:title" content="{{ $title }}">
        <meta property="og:type" content="website">
        @if(!empty($store->bio_description))
            <meta name="description" content="{{ \Illuminate\Support\Str::limit(strip_tags((string) $store->bio_description), 200) }}">
            <meta property="og:description" content="{{ \Illuminate\Support\Str::limit(strip_tags((string) $store->bio_description), 200) }}">
        @endif
    @endif
    @if(!empty($favicon))
        <link rel="icon" href="{{ $favicon }}">
    @endif
    <style nonce="{{ csp_nonce() }}">
        html, body { margin: 0; padding: 0; }
        .bio-html-preview-flag {
            position: sticky; top: 0; z-index: 2147483647;
            background: #fff3cd; color: #7a5b00; text-align: center;
            font: 500 13px/1.4 system-ui, sans-serif; padding: 6px 10px;
        }
    </style>
    @include('builder._runtime-assets')
</head>
<body>
    @if($isPreview)
        <div class="bio-html-preview-flag">{{ ___('Draft preview — not visible to the public until published.') }}</div>
    @endif

    {{-- Sanitized builder HTML (output choke point — re-cleaned at echo), then live
         merge tags + curated-form wiring via the shared BuilderRender pipeline. --}}
    @php
        $mbCtx = ['surface' => 'biolink', 'id' => $store->id ?? null, 'to' => (string) (optional($store->user)->email ?? ''), 'label' => (string) ($store->bio_title ?: $store->title), 'url' => url()->current()];
    @endphp
    {!! \Juwri\Modules\Content\Services\Builder\BuilderRender::page($html ?? '', \Juwri\Modules\Content\Services\Builder\MergeTags::forPost($store), $mbCtx) !!}

    @if(!empty($poweredBy))
        <div style="text-align:center;padding:14px;font:400 12px/1.4 system-ui,sans-serif;opacity:.7;">{{ $poweredBy }}</div>
    @endif
</body>
</html>
