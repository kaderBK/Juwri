{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- tipjar block: tips (bio-t15 A11, SPEC §2 — PRO, gated server-side in
     BioLinkService::blocksFor()/BioLinkController::storeBlock()+updateBlock(); a
     non-entitled store's public page never reaches this partial at all). config:
     {mode:'url'|'amounts', url?, amounts:[int], currency?, label?}. Tip buttons
     link to an external tipping/payment `url` — no in-app payment handling. --}}
@php
    $b = is_array($block ?? null) ? $block : [];
    $cfg = is_array($b['config'] ?? null) ? $b['config'] : [];
    $label = trim((string) ($b['label'] ?? $cfg['label'] ?? '')) ?: ___('Leave a Tip');
    $url = trim((string) ($cfg['url'] ?? '')) ?: trim((string) ($b['url'] ?? '')); // gap-fix: shared editor field submits to the top-level url column
    $currency = trim((string) ($cfg['currency'] ?? ''));
    $mode = ($cfg['mode'] ?? '') === 'amounts' ? 'amounts' : 'url';
    $id = $b['id'] ?? '';

    $amounts = [];
    if ($mode === 'amounts') {
        foreach ((array) ($cfg['amounts'] ?? []) as $a) {
            $n = (int) $a;
            if ($n > 0) {
                $amounts[] = $n;
            }
        }
    }
@endphp
@if ($url !== '')
    <div class="bio-tipjar" @if ($id !== '') data-bio-block="{{ $id }}" @endif>
        @if (! empty($amounts))
            <p class="bio-kicker">{{ $label }}</p>
            <div class="bio-tipjar__amounts">
                @foreach ($amounts as $amt)
                    <a class="bio-tipjar__amt" href="{{ $url }}" rel="noopener">{{ $currency }}{{ $amt }}</a>
                @endforeach
            </div>
        @else
            <a class="bio-cta bio-tipjar__cta" href="{{ $url }}" rel="noopener">{{ $label }}</a>
        @endif
    </div>
@endif
