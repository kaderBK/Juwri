<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront;

use Juwri\Modules\Storefront\Services\Theme\FontPairs;
use Juwri\Modules\Storefront\Services\Theme\Tokens;
use Juwri\Modules\Storefront\Services\ThemeStudio\SectionRestyler;

/**
 * Theme Studio B0.5: the styling the marketplace shell writes into <head> for
 * a Liquid theme. Liquid output can't carry a <style> (the storefront CSP is
 * nonce-based and a theme can't mint nonces), so the TRUSTED shell does it:
 *
 *  - design tokens (--tc-*, --tr-*, --ts-*, --tf-*) from the store's saved
 *    customizer values — overrides only, exactly like the Blade token partial;
 *  - @font-face for the chosen self-hosted font pair + the Saudi Riyal glyph;
 *  - the pinned section library's stylesheet (same-origin, mtime cache-bust);
 *  - E2: the store layout's per-section AI CSS variants (`sections_css`), each
 *    re-cleaned + scoped under `#mb-section-<sid>` (SectionRestyler) and printed
 *    as ONE nonce'd <style> after the library CSS. Only the layout being
 *    rendered (this store's published layout, or its draft in the owner's
 *    preview) is ever read.
 *
 * The Blade partials can't be reused: theme_setting() looks rows up by the raw
 * `mp:<slug>@<ver>` value, while marketplace settings are stored under the
 * versionless `mp:<slug>` key. The caller passes the values it already loaded
 * (published, or draft-merged for the owner preview).
 */
class ShellStyles
{
    /**
     * @param  array<string,mixed>  $storeSettings  the store's customizer values for this theme
     * @param  array<string,mixed>|null  $layout  the store layout being rendered (E2 section variants)
     * @return array{base_css:string,tokens_css:string,tokens_dark_css:string,font_css:string,stylesheets:list<string>,sections_css:string}
     */
    public static function for(MarketplaceTheme $theme, array $storeSettings = [], ?array $layout = null): array
    {
        $template = 'mp:'.$theme->slug.'@'.$theme->version;
        $out = ['base_css' => '', 'tokens_css' => '', 'tokens_dark_css' => '', 'font_css' => FontPairs::riyalFaceCss(), 'stylesheets' => [], 'sections_css' => ''];

        // Library themes: default type stacks (the store's font pair, emitted in
        // tokens_css after this, overrides them) with the Riyal glyph face first —
        // its @font-face is unicode-range limited to U+20C1, so it only ever
        // supplies that one glyph. Without this, library text fell back to the
        // browser's serif and the Riyal sign drew as a box.
        if ($theme->library() !== null) {
            $out['base_css'] = ':root{--tf-body:'.FontPairs::riyalStackPrefix()
                .'system-ui,-apple-system,"Segoe UI",Roboto,"Noto Sans Arabic",Tahoma,sans-serif;'
                .'--tf-heading:var(--tf-body)}'
                .'body{font-family:var(--tf-body);color:var(--tc-text,#141414);background:var(--tc-background,#fff)}';
        }

        try {
            $fieldIds = array_flip(Tokens::fieldIds());
            // A library theme's own design defaults (theme.json "tokens" — e.g. the
            // recipe palette of a Studio starter) sit UNDER the store's values, so a
            // starter looks like itself out of the box and the owner's Design
            // choices still win. Unknown keys are ignored; values are validated by
            // Tokens like any stored value.
            $defaults = $theme->library() !== null && is_array($theme->manifest()['tokens'] ?? null)
                ? array_intersect_key($theme->manifest()['tokens'], $fieldIds)
                : [];
            $values = array_merge(
                $defaults,
                array_filter(array_intersect_key($storeSettings, $fieldIds), static fn ($v) => $v !== null && $v !== '')
            );
            $out['tokens_css'] = Tokens::css(Tokens::forTemplate($template, $values));
            // P3-01: the auto-scheme media block (empty unless the theme declares dark support).
            $out['tokens_dark_css'] = Tokens::css(Tokens::darkBlock($template, $values));

            $pair = Tokens::resolve($template, ['fonts.pair' => $values['fonts.pair'] ?? null])['fonts.pair'] ?? null;
            $out['font_css'] .= FontPairs::fontFaceCss($pair);
        } catch (\Throwable $e) {
            report($e); // styling must never break a storefront — theme literals stand
        }

        if ($theme->isSectioned()) {
            try {
                $out['sections_css'] = SectionRestyler::stylesheet($layout);
            } catch (\Throwable $e) {
                report($e); // a broken variant never breaks a storefront
            }
        }

        if ($library = $theme->library()) {
            $rel = 'assets/liquid-library/'.$library->version.'/library.css';
            if (is_file(public_path($rel))) {
                $out['stylesheets'][] = asset($rel).'?v='.filemtime(public_path($rel));
            }
        }

        return $out;
    }
}
