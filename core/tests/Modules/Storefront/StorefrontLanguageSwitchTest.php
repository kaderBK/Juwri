<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use Juwri\Modules\Storefront\Services\Theme\KeptTemplates;
use Tests\TestCase;

/**
 * P1-G (locales) — the storefront language switch must move the UI locale, not
 * just the menu-content cookie.
 *
 * The app runs mcamara in PREFIX mode, and RememberLocale resolves the UI locale
 * from the URL segment on every request. Ten of the seventeen retained themes
 * switched language with cookie-then-reload, which under prefix mode reloads the
 * SAME prefixed URL and lands back in the original language — menu item names
 * changed, every surrounding label did not.
 *
 * The behaviour now lives in one shared partial included by the layout, so these
 * tests pin the wiring rather than re-testing each theme's bundle.
 */
class StorefrontLanguageSwitchTest extends TestCase
{
    /** The retained storefront templates this contract covers. */
    private const RETAINED = KeptTemplates::MODERN;

    private const PARTIAL = 'store_templates._partials.language-switch';

    public function test_the_shared_layout_includes_the_language_switch_exactly_once(): void
    {
        $layout = file_get_contents(base_path('resources/views/store_templates/layout.blade.php'));

        $this->assertSame(
            1,
            substr_count($layout, self::PARTIAL),
            'store_templates/layout.blade.php must include the shared language switch exactly once.'
        );
    }

    public function test_the_switch_rewrites_the_url_locale_segment(): void
    {
        $partial = file_get_contents(base_path('resources/views/store_templates/_partials/language-switch.blade.php'));

        $this->assertStringContainsString(
            'url.pathname',
            $partial,
            'The language switch must rewrite the URL locale segment — a cookie alone cannot move the UI locale in prefix mode.'
        );
        $this->assertStringContainsString(
            'location.assign',
            $partial,
            'The language switch must navigate to the localized URL.'
        );
    }

    /** Menu item/category names still translate off the cookie, so it must be written too. */
    public function test_the_switch_still_writes_the_menu_language_cookie(): void
    {
        $partial = file_get_contents(base_path('resources/views/store_templates/_partials/language-switch.blade.php'));

        $this->assertStringContainsString('Quick_user_lang_code', $partial);
    }

    /** Capture phase is what lets it beat each theme's own cookie-and-reload handler. */
    public function test_the_switch_listens_in_the_capture_phase(): void
    {
        $partial = file_get_contents(base_path('resources/views/store_templates/_partials/language-switch.blade.php'));

        $this->assertMatchesRegularExpression(
            '/addEventListener\(\s*.click.[\s\S]*?,\s*true\s*\)/',
            $partial,
            'The shared handler must listen in the capture phase, or a theme handler will reload first.'
        );
        $this->assertStringContainsString(
            'stopImmediatePropagation',
            $partial,
            'The shared handler must stop the theme handler from also running its cookie-and-reload path.'
        );
    }

    /**
     * Every retained theme must expose its language options through the shared
     * [data-lang-code] contract (or render no switcher at all). A theme that
     * invents its own attribute silently opts out of the shared handler.
     */
    public function test_retained_themes_use_the_shared_switch_contract(): void
    {
        $offenders = [];

        foreach (self::RETAINED as $theme) {
            $index = base_path("resources/views/store_templates/{$theme}/index.blade.php");
            if (! is_file($index)) {
                continue;
            }
            $html = file_get_contents($index);

            // A theme that renders a language chooser must mark it up with
            // data-lang-code; anything else is invisible to the shared handler.
            if (preg_match('/lang(uage)?[-_ ]?(switch|switcher|chooser|picker)/i', $html)
                && ! str_contains($html, 'data-lang-code')) {
                $offenders[] = $theme;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'These themes render a language chooser that the shared switch cannot see: '.implode(', ', $offenders)
        );
    }
}
