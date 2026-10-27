{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{--
  The owner's info pages (Terms / Privacy / About / Help) as a plain block a theme
  can drop wherever it wants them — the profile screen, first.

  The sibling `info-links` partial is a self-placing FOOTER: it is injected from
  the layout and relocates itself to the bottom of the menu, so it cannot be
  reused anywhere else. This one has no placement and no opinion about where it
  sits; the theme's own markup decides, which is what a profile screen needs.

  Renders nothing when the owner has written no pages. Paints no surface — it
  inherits whatever it is dropped onto — and needs no auth: the pages are public,
  so a guest sees exactly what a signed-in diner sees.
--}}
@php
    $mbilPost = $store ?? null;
    $mbilPages = (is_object($mbilPost) && ! empty($mbilPost->id)) ? storefront_info_pages($mbilPost->id) : [];
@endphp

@if(! empty($mbilPages))
<nav class="mbil-links" aria-label="{{ ___('Information') }}">
    @foreach($mbilPages as $mbilSlug => $mbilLabel)
        <a class="mbil-link" href="{{ storefront_localized_link(route('store.infoPage', [$mbilPost->id, $mbilSlug])) }}">{{ $mbilLabel }}</a>
    @endforeach
</nav>

<style @if(function_exists('csp_nonce')) nonce="{{ csp_nonce() }}" @endif>
.mbil-links{display:flex;flex-wrap:wrap;gap:8px 10px;padding:4px 0 2px}
.mbil-link{
    text-decoration:none;font-size:12.5px;font-weight:600;line-height:1.2;color:inherit;opacity:.82;
    padding:8px 14px;border-radius:999px;
    border:1px solid color-mix(in srgb, currentColor 22%, transparent);
    transition:opacity .15s ease;
}
.mbil-link:hover,.mbil-link:focus-visible{opacity:1}
</style>
@endif
