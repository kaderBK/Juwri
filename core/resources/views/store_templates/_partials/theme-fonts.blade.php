{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    /* Customizer v2 — WI-T2: the @font-face block for the SELECTED font pair.
     *
     * Self-hosted only. Before this partial the shared layout pulled the owner's
     * body font from fonts.googleapis.com — a render-blocking third-party request
     * on every storefront view, against the repo's own "no CDN, CSP blocks them"
     * rule. The pairs now ship as WOFF2 out of assets/ (registry:
     * core/config/theme-fonts.php, reader: Juwri\Modules\Storefront\Services\Theme\FontPairs).
     *
     * Only the chosen pair's faces are emitted — never the whole registry, which
     * would be ~1.2 MB of woff2 for one theme's worth of type.
     *
     * With no pair chosen (`fonts.pair` = the `none` sentinel, the default every
     * theme inherits from the shared `tokens` fragment) this partial renders
     * NOTHING: no style tag, no @font-face, no bytes. That is the parity
     * contract — a store that has never opened the control must render exactly
     * what it renders today, and Tokens likewise emits no --tf-heading/--tf-body.
     *
     * (Written "style tag", not the literal markup: CspEnforceTest greps this
     * directory for opening style tags without a nonce and cannot tell prose
     * from markup.)
     *
     * The single literal `theme_setting($store->id, 'fonts.pair')` below is also
     * what ThemeSchemaLintTest greps to prove the control is not dead, so keep it
     * a literal — a variable key would be invisible to the lint.
     */
    $__fontPair = null;
    $__fontFaceCss = '';

    $__fontPost = $store ?? null;
    if (is_object($__fontPost) && !empty($__fontPost->id)) {
        try {
            $__fontTemplate = (string) (store_options($__fontPost->id)->store_template ?? '');
            // P3-12: a kit theme renders its own tokens (and fonts) even when the store's saved slug is an old, aliased one
            if (\Juwri\Modules\Storefront\Services\Theme\KitThemes::isKit($theme ?? null)) {
                $__fontTemplate = (string) $theme;
            }
            if ($__fontTemplate !== '') {
                // Resolved through Tokens so the pair falls back to the THEME's
                // declared default exactly the way every other token does — one
                // resolution path, not two that can disagree.
                $__fontPair = \Juwri\Modules\Storefront\Services\Theme\Tokens::resolve($__fontTemplate, [
                    'fonts.pair' => theme_setting($__fontPost->id, 'fonts.pair'),
                ])['fonts.pair'] ?? null;
                $__fontFaceCss = \Juwri\Modules\Storefront\Services\Theme\FontPairs::fontFaceCss($__fontPair);
            }
        } catch (\Throwable $e) {
            // A storefront must never 500 over typography: no faces means the
            // theme's own font stack stands, which is the pre-v2 rendering.
            $__fontFaceCss = '';
        }
    }
@endphp
@if($__fontFaceCss !== '')
<style nonce="{{ csp_nonce() }}">{!! $__fontFaceCss !!}</style>
@endif
