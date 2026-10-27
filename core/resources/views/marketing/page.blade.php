{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@extends('marketing.layout')

@section('title', ($page->title ?? ___('Page')) . ' - ' . (brand_context()->isReseller() ? seo_brand() : ($settings->site_title ?? config('app.name'))))

@section('meta_description', $page->meta_description ?? $page->title ?? '')

{{-- SEO: neutral/single CMS pages consolidate every /{locale}/ prefix onto one
     canonical (default-locale URL); genuinely-translated slugs self-canonical.
     Computed in HomeController@pageSeo alongside $pageHreflang (read by the
     marketing frame's head). Admin\PageController::builderPreview renders this
     same view with its own $pageCanonical. --}}
@section('canonical', $pageCanonical ?? url()->current())

@section('content')

    @if (($page->builder ?? 'classic') === 'html')
        {{-- Builder.js (html-mode) CMS page: self-styled inline-CSS HTML rendered
             full-bleed. Output-time XSS choke point — the rendered content is ALWAYS
             re-sanitized through the allowlist DOM sanitizer at echo (idempotent —
             already cleaned on save/publish). --}}
        @php
            $mbCtx = ['surface' => 'cms', 'id' => $page->id ?? null, 'to' => (string) data_get($settings, 'email', ''), 'label' => (string) ($page->title ?? ''), 'url' => url()->current()];
        @endphp
        <div class="cms-builder-page">
            {!! \Juwri\Modules\Content\Services\Builder\BuilderRender::page($page->content, \Juwri\Modules\Content\Services\Builder\MergeTags::forPage($page, $settings ?? []), $mbCtx) !!}
        </div>
        @include('builder._runtime-assets')
    @elseif (($page->builder ?? 'classic') === 'trusted')
        {{-- Trusted (admin-authored) raw HTML+JS — rendered as-authored, only inline
             <script nonce="{{ csp_nonce() }}">/<style> nonce-stamped. NEVER sanitised (set by admins only). --}}
        {!! \Juwri\Core\Support\TrustedHtml::render($page->content) !!}
    @else
        @include('marketing.partials.page-hero', [
            'title' => $page->title ?? ___('Untitled'),
            'eyebrow' => ($page->updated_at ?? null) ? (___('Last updated') . ' · ' . $page->updated_at->format('M j, Y')) : ___('Page'),
        ])

        {{-- Classic (TinyMCE) page body: admin-authored rich text, echoed as-is
             like before; the shared .mk-prose styles it. --}}
        <section class="mk-page-body">
            <div class="mk-wrap">
                <div class="mk-prose">
                    {!! $page->body ?? $page->content ?? '<p>' . ___('This page has no content yet.') . '</p>' !!}
                </div>
            </div>
        </section>
    @endif

@endsection
