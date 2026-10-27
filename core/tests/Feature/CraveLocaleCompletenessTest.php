<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Juwri\Modules\Storefront\Services\Rendering\LiquidEnvironmentFactory;
use Juwri\Modules\Storefront\Services\Rendering\LiquidRenderer;
use Juwri\Modules\Storefront\Services\Rendering\RenderClient;
use Juwri\Modules\Storefront\Storefront\MarketplaceThemeResolver;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * P9-1: the crave reference theme must ship a complete Arabic (+English)
 * locale bundle so no `t` key falls back to its English source on an
 * Arabic storefront (the P8-11 parity gap). The `t` filter is read-only —
 * keys never auto-create — so completeness is enforced here.
 */
class CraveLocaleCompletenessTest extends TestCase
{
    private function renderer(): LiquidRenderer
    {
        return new LiquidRenderer(new LiquidEnvironmentFactory(), new RenderClient());
    }

    /** Every `'<key>' | t` used in the crave templates/snippets. */
    private function templateKeys(): array
    {
        $dir = resource_path('liquid-themes/crave/1');
        $keys = [];
        foreach (File::allFiles($dir) as $file) {
            if ($file->getExtension() !== 'liquid') {
                continue;
            }
            // matches: 'Some key' | t   and   "Some key" | t (quote-char class, no crossing)
            $body = $file->getContents();
            if (preg_match_all('/\'([^\']+)\'\s*\|\s*t\b/u', $body, $m)) {
                foreach ($m[1] as $key) {
                    $keys[$key] = true;
                }
            }
            if (preg_match_all('/"([^"]+)"\s*\|\s*t\b/u', $body, $m)) {
                foreach ($m[1] as $key) {
                    $keys[$key] = true;
                }
            }
        }

        return array_keys($keys);
    }

    public function test_every_crave_t_key_has_an_arabic_and_english_entry(): void
    {
        $theme = MarketplaceThemeResolver::resolve('mp:crave@1', gate: false);
        $this->assertNotNull($theme, 'crave reference theme resolves');

        $ar = $theme->locales('ar');
        $en = $theme->locales('en');

        $keys = $this->templateKeys();
        $this->assertNotEmpty($keys, 'found t-keys in the crave templates');

        $missingAr = [];
        $missingEn = [];
        foreach ($keys as $key) {
            if (! isset($ar[$key]) || trim($ar[$key]) === '') {
                $missingAr[] = $key;
            }
            if (! isset($en[$key]) || trim($en[$key]) === '') {
                $missingEn[] = $key;
            }
        }

        $this->assertSame([], $missingAr, 'crave locales/ar.json is missing keys: '.implode(' | ', $missingAr));
        $this->assertSame([], $missingEn, 'crave locales/en.json is missing keys: '.implode(' | ', $missingEn));
    }

    public function test_theme_only_keys_render_in_arabic_not_english(): void
    {
        App::setLocale('ar');
        $theme = MarketplaceThemeResolver::resolve('mp:crave@1', gate: false);
        $registers = ['theme_locale_map' => $theme->locales('ar')];

        // The previously-falling-back keys must now render in Arabic. A key that
        // is ALSO a platform lang slug is served by the platform (which wins, and
        // is itself Arabic) — either way, the English source must not survive.
        $probe = ["Today's specials", 'Special', 'Popular', 'Tap to continue', 'Toggle layout'];
        foreach ($probe as $key) {
            // double-quoted Liquid literal (keys contain apostrophes, no double-quotes)
            $out = $this->renderer()->render('{{ "'.$key.'" | t }}', [], 'live', $registers);
            $this->assertNotSame($key, $out, "'$key' still falls back to its English source");

            // Keys that are NOT a platform slug must come from the theme bundle verbatim.
            if (! \Illuminate\Support\Facades\Lang::has('lang.'.\Illuminate\Support\Str::slug($key, '_'))) {
                $this->assertSame($ar[$key], $out, "'$key' renders its theme Arabic entry");
            }
        }
    }
}
