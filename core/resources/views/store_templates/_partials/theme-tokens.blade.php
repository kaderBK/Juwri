{{-- SPDX-License-Identifier: AGPL-3.0-only --}}
{{-- Copyright (c) JUWRI LTD --}}

@php
    /* Customizer v2 — WI-T1/WI-T3: the design-token :root block.
     *
     * One include, every theme. The owner sets five colour roles plus a corner
     * and a spacing scale; Juwri\Modules\Storefront\Services\Theme\Tokens derives the whole ramp
     * server-side (sRGB mix math, RESEARCH.md §2 — NOT color-mix(), which the
     * old Android WebViews in this audience do not have) and this partial writes
     * it out once.
     *
     * Values are resolved as "the owner's setting, else this theme's own declared
     * default", and every theme.json carries the literals already sitting in its
     * stylesheet — so a store that customized nothing gets tokens that reproduce
     * exactly what it renders today. Themes consume them as
     * `var(--tc-primary, #3fd463)`, keeping the literal as the fallback.
     *
     * The reads below are written out one literal key at a time on purpose:
     * ThemeSchemaLintTest greps the helper calls in this file to prove no schema
     * field is a control that does nothing, and a loop would be invisible to it.
     */
    $__tokPost = $store ?? null;
    $__tokCss  = '';
    $__tokDarkCss = '';

    if (is_object($__tokPost) && !empty($__tokPost->id)) {
        try {
            $__tokTemplate = (string) (store_options($__tokPost->id)->store_template ?? '');
            // P3-12: a kit theme renders its own tokens (and fonts) even when the store's saved slug is an old, aliased one
            if (\Juwri\Modules\Storefront\Services\Theme\KitThemes::isKit($theme ?? null)) {
                $__tokTemplate = (string) $theme;
            }
            if ($__tokTemplate !== '') {
                $__tokValues = [
                        'colors.primary'    => theme_setting($__tokPost->id, 'colors.primary'),
                        'colors.background' => theme_setting($__tokPost->id, 'colors.background'),
                        'colors.surface'    => theme_setting($__tokPost->id, 'colors.surface'),
                        'colors.text'       => theme_setting($__tokPost->id, 'colors.text'),
                        'colors.accent'     => theme_setting($__tokPost->id, 'colors.accent'),
                        'layout.radius'     => theme_setting($__tokPost->id, 'layout.radius'),
                        'layout.density'    => theme_setting($__tokPost->id, 'layout.density'),
                        /* WI-T2: the selected self-hosted pairing → --tf-heading/--tf-body.
                           Empty (the default) emits neither var; the matching @font-face
                           rules come from _partials/theme-fonts.blade.php. */
                        'fonts.pair'        => theme_setting($__tokPost->id, 'fonts.pair'),
                        /* P3-01: dark scheme. A theme that declares scheme.mode + colors_dark.*
                           gets the dark roles (mode dark: under the normal --tc-* names;
                           mode auto: inside @media (prefers-color-scheme: dark), with `initial`
                           for any light override that has no dark twin). Themes that declare
                           none of these resolve nothing here and keep the single block. */
                        'scheme.mode'       => theme_setting($__tokPost->id, 'scheme.mode'),
                        'colors_dark.primary'    => theme_setting($__tokPost->id, 'colors_dark.primary'),
                        'colors_dark.background' => theme_setting($__tokPost->id, 'colors_dark.background'),
                        'colors_dark.surface'    => theme_setting($__tokPost->id, 'colors_dark.surface'),
                        'colors_dark.text'       => theme_setting($__tokPost->id, 'colors_dark.text'),
                        'colors_dark.accent'     => theme_setting($__tokPost->id, 'colors_dark.accent'),
                    ];
                $__tokCss = \Juwri\Modules\Storefront\Services\Theme\Tokens::css(
                    \Juwri\Modules\Storefront\Services\Theme\Tokens::forTemplate($__tokTemplate, $__tokValues)
                );
                $__tokDarkCss = \Juwri\Modules\Storefront\Services\Theme\Tokens::css(
                    \Juwri\Modules\Storefront\Services\Theme\Tokens::darkBlock($__tokTemplate, $__tokValues)
                );
            }
        } catch (\Throwable $e) {
            // A storefront must never 500 over styling. No tokens = every theme
            // literal stands, which is exactly the pre-v2 rendering.
            $__tokCss = '';
            $__tokDarkCss = '';
        }
    }
@endphp
@if($__tokCss !== '' || $__tokDarkCss !== '')
<style nonce="{{ csp_nonce() }}">:root{ {!! $__tokCss !!} }@if($__tokDarkCss !== '')@media (prefers-color-scheme: dark){:root{ {!! $__tokDarkCss !!} }}@endif</style>
@endif
