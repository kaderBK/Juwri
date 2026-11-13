{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- loyalty block: enroll / wallet pass CTA (bio-t15 A7, SPEC §2). config:
     {mode:'auto'|'url', provider:'wallet'|'other', url?, label?}. `mode:'auto'`'s
     `url` is resolved server-side by BioLinkService::resolveLoyaltyBlocks() only
     when the store has an active loyalty program (never rebuilt here); `mode:'url'`
     keeps the owner-typed destination. `provider` only changes the icon/label copy
     (no anonymous single-tap wallet-save exists — see LEDGER "Contract changes").
     Degrades to nothing without a URL. --}}
@php
    $b = is_array($block ?? null) ? $block : [];
    $cfg = is_array($b['config'] ?? null) ? $b['config'] : [];
    $wallet = ($cfg['provider'] ?? '') === 'wallet';
    $label = trim((string) ($b['label'] ?? $cfg['label'] ?? '')) ?: ($wallet ? ___('Add to Wallet') : ___('Join Loyalty'));
    $url = trim((string) ($cfg['url'] ?? '')) ?: trim((string) ($b['url'] ?? '')); // gap-fix: shared editor field submits to the top-level url column
    $id = $b['id'] ?? '';
@endphp
@if ($url !== '')
    <a class="bio-cta bio-cta--loyalty" href="{{ $url }}" rel="noopener" @if ($id !== '') data-bio-block="{{ $id }}" @endif>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2 3 6v6c0 5 3.8 9.4 9 10 5.2-.6 9-5 9-10V6l-9-4zm0 2.2 7 3.1v4.7c0 4-3 7.4-7 7.9-4-.5-7-3.9-7-7.9V7.3l7-3.1zM11 7v4H7v2h4v4h2v-4h4v-2h-4V7h-2z"/></svg>
        <span class="bio-cta__label">{{ $label }}</span>
    </a>
@endif
