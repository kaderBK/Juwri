{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

{{-- google-suite Lane D · store map embed. Keyless Google Maps iframe (no API
     key, no billing): `?output=embed`. Renders only when the owner opts in
     (post_option store_map_embed = '1', default OFF — see LEDGER D0) AND there
     is something to point at. Coords win when a branch has them; otherwise the
     store address; otherwise nothing. The iframe frames www.google.com, which
     StorefrontPolicy allows in frame-src (never script-src).

     Self-contained: needs only $store. Honours a passed $activeBranch when the
     including view has one (info-page does not), else resolves the first active
     branch (by position) that carries coordinates. Branch coords are commonly
     NULL in prod (no owner form collects them), so the address path is normal. --}}
@php
    $smEnabled = (string) store_options($store->id, 'store_map_embed', '0') === '1';

    $smBranch = null;
    if ($smEnabled) {
        // Prefer an explicitly-selected branch with coords, else the first
        // active branch (position order) that has both lat + lng.
        $smActive = $activeBranch ?? null;
        if (\Juwri\Core\Support\Views\StoreViewHelpers::branchHasCoords($smActive)) {
            $smBranch = $smActive;
        } else {
            foreach ($store->branches as $smB) {
                if ($smB->active && \Juwri\Core\Support\Views\StoreViewHelpers::branchHasCoords($smB)) {
                    $smBranch = $smB;
                    break;
                }
            }
        }
    }

    $smSrc = null;   // iframe embed src
    $smOpen = null;  // "open in Google Maps" link (coords-preferring, D4)
    $smLabel = null;
    if ($smEnabled) {
        if ($smBranch) {
            $smQuery = $smBranch->latitude.','.$smBranch->longitude;
            $smSrc = 'https://www.google.com/maps?q='.rawurlencode($smQuery).'&output=embed';
            $smOpen = 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($smQuery);
            $smLabel = $smBranch->name ?: $store->title;
        } elseif (trim((string) $store->address) !== '') {
            $smSrc = 'https://www.google.com/maps?q='.rawurlencode((string) $store->address).'&output=embed';
            $smOpen = 'https://www.google.com/maps/search/?api=1&query='.rawurlencode((string) $store->address);
            $smLabel = $store->title;
        }
    }
@endphp

@if($smSrc)
    <section class="store-map" aria-label="{{ ___('Location map') }}"
        style="margin:22px 0 0;border:1px solid color-mix(in srgb, currentColor 16%, transparent);border-radius:16px;overflow:hidden;">
        <iframe
            src="{{ $smSrc }}"
            title="{{ ___('Map') }}{{ $smLabel ? ' — '.$smLabel : '' }}"
            width="100%" height="260"
            style="display:block;width:100%;height:260px;border:0;"
            loading="lazy" referrerpolicy="no-referrer-when-downgrade"
            allowfullscreen></iframe>
        @if($smOpen)
            {{-- D4: coords-preferring "open in Maps" link (uses branch coords when
                 present, else the address). Themes' own address-only map links
                 live in Lane A index blades — recorded as an F0 follow-up. --}}
            <a href="{{ $smOpen }}" target="_blank" rel="noopener noreferrer"
                style="display:block;padding:10px 14px;font-size:13px;font-weight:700;text-decoration:none;color:inherit;border-top:1px solid color-mix(in srgb, currentColor 12%, transparent);">
                {{ ___('Open in Google Maps') }}
            </a>
        @endif
    </section>
@endif
