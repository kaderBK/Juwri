{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- giftcard block: surfaces the store's existing gift-card feature (bio-t15 A6,
     SPEC §2). config: {mode:'auto'|'url', url?, label?}. `mode:'auto'`'s `url` is
     resolved server-side by BioLinkService::resolveGiftcardBlocks() only when the
     gift-card feature is live for this store (never rebuilt here); `mode:'url'`
     keeps the owner-typed destination. Degrades to nothing without a URL. --}}
@php
    $b = is_array($block ?? null) ? $block : [];
    $cfg = is_array($b['config'] ?? null) ? $b['config'] : [];
    $label = trim((string) ($b['label'] ?? $cfg['label'] ?? '')) ?: ___('Buy a Gift Card');
    $url = trim((string) ($cfg['url'] ?? '')) ?: trim((string) ($b['url'] ?? '')); // gap-fix: shared editor field submits to the top-level url column, not config.url
    $id = $b['id'] ?? '';
@endphp
@if ($url !== '')
    <a class="bio-cta bio-cta--giftcard" href="{{ $url }}" rel="noopener" @if ($id !== '') data-bio-block="{{ $id }}" @endif>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 6h-2.2a2.5 2.5 0 0 0-4.3-2.5A2.5 2.5 0 0 0 9 6H4a1 1 0 0 0-1 1v3a1 1 0 0 0 1 1v9a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-9a1 1 0 0 0 1-1V7a1 1 0 0 0-1-1zM12 20H5v-9h7v9zm0-11H5V8h7v1zm0-3.5A1.5 1.5 0 1 1 13.5 7H12V5.5zm2-1.5A1.5 1.5 0 1 1 15.5 5.5V7H14V4zm5 5h-7V8h7v1zm0 11h-7v-9h7v9z"/></svg>
        <span class="bio-cta__label">{{ $label }}</span>
    </a>
@endif
