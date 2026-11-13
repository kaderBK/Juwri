<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\ThemeStudio\Ai;

use Illuminate\Support\Collection;
use Juwri\Core\Models\Language;
use Juwri\Modules\Storefront\Services\Rendering\RenderFailedException;
use Juwri\Modules\Storefront\Services\ThemeStudio\LayoutDocument;
use Juwri\Modules\Storefront\Storefront\CommerceRuntime;
use Juwri\Modules\Storefront\Storefront\MarketplaceTheme;
use Juwri\Modules\Storefront\Storefront\SectionedTemplate;
use Juwri\Modules\Storefront\Storefront\ShellStyles;
use Juwri\Modules\Storefront\Storefront\ThemeContext;
use Juwri\Modules\Storefront\Storefront\ThemeSettingValues;

/**
 * Theme Studio phase D step 8: render proposals.
 *
 *  - check(): every section of a proposal rendered ALONE in strict upload
 *    mode (unknown variable / filter = error) against the given store data,
 *    in each checked language; a section that fails anywhere is dropped and
 *    reported. Same globals/registers as the storefront's Liquid branch.
 *  - page(): the full marketplace shell for a proposal (theme + layout +
 *    tokens) against render vars — the superadmin preview against a demo
 *    store. (The owner preview renders through StoreController@publicView.)
 *  - localize(): a layout with `i18n.<lang>` copy folded into the settings,
 *    so a render in that language shows the translated text.
 */
class ProposalRenderer
{
    /**
     * @param  array<string,mixed>  $layout  normalized + sanitized
     * @param  array<string,mixed>  $vars  StoreVars
     * @param  list<string>  $languages  codes to check (default first)
     * @return array{layout:array<string,mixed>,dropped:list<array{sid:string,type:string,lang:string,error:string}>}
     */
    public function check(MarketplaceTheme $theme, array $layout, array $vars, array $languages, string $defaultLanguage): array
    {
        $dropped = [];
        $original = app()->getLocale();

        try {
            foreach ($languages as $lang) {
                $localized = self::localize($layout, $lang, $defaultLanguage);
                [$globals, $registers] = $this->context($theme, $localized, $vars, $lang);

                foreach ($layout['order'] as $sid) {
                    $section = $localized['sections'][$sid] ?? null;
                    if (! is_array($section) || ! isset($layout['sections'][$sid])) {
                        continue;
                    }
                    $single = ['schema' => 1, 'sections' => [$sid => $section], 'order' => [$sid]];
                    try {
                        app(SectionedTemplate::class)->render($theme, $globals, $registers, [], 'upload', $single);
                    } catch (\Throwable $e) {
                        $cause = $e->getPrevious() ? ' ('.$e->getPrevious()->getMessage().')' : '';
                        $dropped[] = ['sid' => (string) $sid, 'type' => (string) $section['type'], 'lang' => $lang, 'error' => mb_substr($e->getMessage().$cause, 0, 300)];
                        unset($layout['sections'][$sid]);
                    }
                }
                $layout['order'] = array_values(array_filter($layout['order'], fn ($sid) => isset($layout['sections'][$sid])));
            }
        } finally {
            app()->setLocale($original);
        }

        foreach ($layout['i18n'] ?? [] as $lang => $strings) {
            foreach ((array) $strings as $path => $value) {
                if (! isset($layout['sections'][strtok((string) $path, '.')])) {
                    unset($layout['i18n'][$lang][$path]);
                }
            }
        }

        return ['layout' => $layout, 'dropped' => $dropped];
    }

    /**
     * The full storefront page (marketplace shell) for a proposal.
     *
     * @param  array<string,mixed>  $layout
     * @param  array<string,string>  $tokens  colors.* / layout.* / fonts.pair
     * @param  array<string,mixed>  $vars
     *
     * @throws RenderFailedException when nothing renders
     */
    public function page(MarketplaceTheme $theme, array $layout, array $tokens, array $vars, string $lang, string $defaultLanguage): string
    {
        $original = app()->getLocale();
        try {
            $localized = self::localize($layout, $lang, $defaultLanguage);
            [$globals, $registers, $language] = $this->context($theme, $localized, $vars, $lang, $tokens);
            $body = app(SectionedTemplate::class)->render($theme, $globals, $registers, [], 'live', $localized);
            if (trim($body) === '') {
                throw new RenderFailedException('the proposal rendered no sections');
            }

            return view('store_templates.marketplace-shell', [
                'store' => $vars['store'],
                'body' => $body,
                'lang' => $language->code,
                'dir' => strtolower((string) ($language->direction ?? '')) === 'rtl' ? 'rtl' : 'ltr',
                'shellStyles' => ShellStyles::for($theme, $tokens),
                'studio' => false,
            ])->render();
        } finally {
            app()->setLocale($original);
        }
    }

    /**
     * The layout as it renders in $lang: `i18n.<lang>."<sid>.<key>"` values
     * replace the default-language settings. The default language (and a
     * language without strings) renders the settings as they are.
     *
     * @param  array<string,mixed>  $layout
     * @return array<string,mixed>
     */
    public static function localize(array $layout, string $lang, string $defaultLanguage): array
    {
        if ($lang === $defaultLanguage) {
            return $layout;
        }
        foreach ((array) ($layout['i18n'][$lang] ?? []) as $path => $value) {
            [$sid, $key] = array_pad(explode('.', (string) $path, 2), 2, '');
            if ($key !== '' && isset($layout['sections'][$sid]) && is_string($value)) {
                $layout['sections'][$sid]['settings'][$key] = $value;
            }
        }

        return $layout;
    }

    /**
     * Globals + registers exactly as the storefront's Liquid branch builds them.
     *
     * @param  array<string,mixed>  $layout
     * @param  array<string,mixed>  $vars
     * @param  array<string,string>  $tokens
     * @return array{0:array<string,mixed>,1:array<string,mixed>,2:Language}
     */
    protected function context(MarketplaceTheme $theme, array $layout, array $vars, string $lang, array $tokens = []): array
    {
        $pool = $vars['menu_languages'] ?? [];
        $pool = $pool instanceof Collection ? $pool : new Collection(is_array($pool) ? $pool : []);
        $language = $pool->firstWhere('code', $lang) ?? StoreVars::language($lang);
        $vars['default_menu_language'] = $language;
        app()->setLocale($language->code);

        $vars['settings'] = ThemeSettingValues::resolve($theme, $tokens, LayoutDocument::normalize($layout));

        $themeContext = app(ThemeContext::class);
        $globals = $themeContext->build($vars);
        try {
            $commerce = app(CommerceRuntime::class)->registers($vars, $theme);
        } catch (\Throwable $e) {
            report($e);
            $commerce = [];
        }
        $registers = $themeContext->registers($vars) + $commerce + [
            'theme_asset_map' => $theme->assetMap(),
            'theme_locale_map' => $theme->locales($language->code),
            'studio_mode' => false,
        ];

        return [$globals, $registers, $language];
    }
}
