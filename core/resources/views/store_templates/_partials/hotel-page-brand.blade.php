{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    /* Hotel guest-layer — standalone-page store-identity bridge (lane D / D1).
     *
     * The three standalone hotel guest pages (hotel/booking, booking-manage,
     * guest-folio) are self-contained HTML documents: they load NONE of a theme's
     * stylesheet, so the in-storefront trick of `var(--brand, var(--theme-color-1))`
     * has nothing to resolve against. This partial therefore resolves the store's
     * accent SERVER-SIDE and emits the shared `--mbh-*` token contract (SPEC §2.4)
     * as a nonced :root block. The pages consume the tokens with the pre-restyle
     * literals as their var() fallback, so a store with no usable accent renders
     * byte-for-byte what it renders today.
     *
     * Accent priority: theme-customizer `colors.primary` override → the store's own
     * `$store->color` (general settings) → a neutral fallback (today's palette).
     *
     * Radius + font follow the SAME customizer tokens the storefront uses: the two
     * v2 partials below emit `--tr-card`/`--tr-control` (radius scale) and
     * `--tf-body` (self-hosted pairing, which FontPairs prefixes with the
     * 'Saudi Riyal' family) — both gracefully absent when unset, so our var()
     * fallbacks keep money-glyph and metrics identical. Money rendering itself is
     * NOT touched anywhere in this pack (SPEC §2.5 / constraint #13).
     */
    $__hpbFallback = '#0d6efd';

    $__hpbRaw = '';
    try {
        $__hpbRaw = (string) (theme_setting($store->id, 'colors.primary') ?? '');
    } catch (\Throwable $e) {
        $__hpbRaw = '';
    }
    if ($__hpbRaw === '') {
        $__hpbRaw = (string) ($store->color ?? '');
    }

    // Expand 3-digit shorthand, then demand a full 6-digit hex; anything else is
    // treated as "no usable accent" and falls back to today's palette.
    $__hpbHex = trim($__hpbRaw);
    if (preg_match('/^#([0-9a-fA-F])([0-9a-fA-F])([0-9a-fA-F])$/', $__hpbHex, $__hpbM)) {
        $__hpbHex = '#'.$__hpbM[1].$__hpbM[1].$__hpbM[2].$__hpbM[2].$__hpbM[3].$__hpbM[3];
    }
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $__hpbHex)) {
        $__hpbHex = $__hpbFallback;
    }

    [$__hpbR, $__hpbG, $__hpbB] = sscanf($__hpbHex, "#%02x%02x%02x");
    $__hpbR = (int) $__hpbR; $__hpbG = (int) $__hpbG; $__hpbB = (int) $__hpbB;

    // WCAG relative-luminance pick for the on-accent ink: near-black on a light
    // accent, white on a dark one — so a button label always stays legible.
    $__hpbLum = 0.2126 * \Juwri\Core\Support\Views\StoreViewHelpers::srgbLuminanceChannel((float) $__hpbR)
        + 0.7152 * \Juwri\Core\Support\Views\StoreViewHelpers::srgbLuminanceChannel((float) $__hpbG)
        + 0.0722 * \Juwri\Core\Support\Views\StoreViewHelpers::srgbLuminanceChannel((float) $__hpbB);
    $__hpbOnAccent = $__hpbLum > 0.45 ? '#111111' : '#ffffff';

    // Low-alpha accent washes for badges, the selected-room card and focus rings —
    // computed as rgba() (not color-mix) so old Android WebViews render them too.
    $__hpbSoft   = "rgba($__hpbR,$__hpbG,$__hpbB,0.12)";
    $__hpbBorder = "rgba($__hpbR,$__hpbG,$__hpbB,0.35)";
    $__hpbRing   = "rgba($__hpbR,$__hpbG,$__hpbB,0.30)";
@endphp
{{-- Reuse the storefront's own token + font-face resolution so radius/font on these
     standalone pages track the SAME customizer settings, self-hosted and CSP-safe.
     Each emits its own nonced style and renders nothing when the store customized
     neither (parity contract) — our var() fallbacks then stand. --}}
@include('store_templates._partials.theme-tokens')
@include('store_templates._partials.theme-fonts')
<style nonce="{{ csp_nonce() }}">
:root{
    --mbh-accent: {{ $__hpbHex }};
    --mbh-on-accent: {{ $__hpbOnAccent }};
    --mbh-accent-soft: {{ $__hpbSoft }};
    --mbh-accent-border: {{ $__hpbBorder }};
    --mbh-accent-ring: {{ $__hpbRing }};
    --mbh-radius: var(--tr-card, 14px);
    --mbh-radius-control: var(--tr-control, 10px);
    --mbh-font: var(--tf-body, 'Saudi Riyal', system-ui, -apple-system, "Segoe UI", sans-serif);
    /* Light neutral surfaces stay fixed and readable under any accent (SPEC §2.4). */
    --mbh-bg: #f6f7f9;
    --mbh-surface: #ffffff;
    --mbh-ink: #111111;
    --mbh-muted: #667;
    --mbh-border: #e6e8ec;
}
</style>
