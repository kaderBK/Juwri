<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use Juwri\Modules\Storefront\Services\Theme\KeptTemplates;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Storefront links must keep the viewer on their own locale.
 *
 * Reported as "clicking an item on soiree changed the language". The item sheet
 * was innocent: the MENU BANNER anchor carried the stored `link_url`
 * (`/{slug}?item=12`, written unprefixed by the owner and by the demo seeder),
 * and in URL-prefix mode an unprefixed URL *is* the default-locale page — so an
 * English diner who tapped a banner landed on the store's Arabic default.
 *
 * The same hole ran much wider: `route('publicView', $slug)` is not locale
 * aware, so every "Back to Menu" / "View Menu" link on the themes' track and
 * account pages did it too (93 call sites across the 17 kept templates).
 *
 * Two guards here, both cheap:
 *  1. `storefront_localized_link()` behaves — same-site links get the current
 *     locale, foreign links and non-http schemes are never touched, and session
 *     mode is left alone.
 *  2. No kept template renders a raw same-site storefront link any more. This is
 *     the part that stops the next theme from reintroducing it: a grep-level
 *     assertion over the Blade sources, which no rendering test would catch
 *     because the demo stores' banners are seeded per environment.
 */
class StorefrontLocalizedLinkTest extends TestCase
{
    /** The 17 templates that are kept; everything else is scheduled for deletion. */
    private const KEPT = KeptTemplates::MODERN;

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.include_language_code' => true]);
    }

    /** mcamara owns the URL prefix, so the package locale is what matters here. */
    private function useLocale(string $locale): void
    {
        app()->setLocale($locale);
        LaravelLocalization::setLocale($locale);
    }

    public function test_same_site_links_take_the_current_locale(): void
    {
        /* Which locale owns the bare URL is environment config
           (hideDefaultLocaleInURL + DEFAULT_LANGUAGE differ between phpunit.xml
           and the deployed env), so assert the INVARIANT: a non-default locale
           gets its prefix, and the query string survives. */
        $locale = $this->nonDefaultLocale();
        $this->useLocale($locale);

        $this->assertSame(
            url('/'.$locale.'/soiree-demo').'?item=65556',
            storefront_localized_link(url('/soiree-demo').'?item=65556'),
            'An unprefixed same-site link must be pulled onto the viewer locale, query intact.'
        );
        $this->assertSame(
            url('/'.$locale.'/soiree-demo'),
            storefront_localized_link('/soiree-demo'),
            'A root-relative link must localize too.'
        );
    }

    public function test_foreign_and_non_http_links_are_never_rewritten(): void
    {
        $this->useLocale($this->nonDefaultLocale());

        foreach (['https://instagram.com/soiree', '#', 'mailto:hi@example.com', 'tel:+96650000', '//cdn.example.com/x.png'] as $untouched) {
            $this->assertSame($untouched, storefront_localized_link($untouched));
        }

        $this->assertNull(storefront_localized_link(null));
        $this->assertSame('', storefront_localized_link(''));
    }

    public function test_session_mode_is_left_alone(): void
    {
        config(['settings.include_language_code' => false]);
        $this->useLocale($this->nonDefaultLocale());

        $url = url('/soiree-demo').'?item=1';
        $this->assertSame($url, storefront_localized_link($url), 'Session mode serves one URL per page.');
    }

    public function test_default_locale_keeps_the_unprefixed_url(): void
    {
        // The default locale is hidden in the URL, so localizing must not add a
        // prefix that would cost every visitor a 302 hop.
        $default = LaravelLocalization::getDefaultLocale();
        $this->useLocale($default);

        $localized = storefront_localized_link(url('/soiree-demo'));
        $this->assertStringNotContainsString('/'.$default.'/soiree-demo', (string) $localized);
    }

    /** The locale that DOES carry a prefix in this environment. */
    private function nonDefaultLocale(): string
    {
        $default = LaravelLocalization::getDefaultLocale();
        foreach (array_keys(LaravelLocalization::getSupportedLocales()) as $code) {
            if ($code !== $default) {
                return $code;
            }
        }
        $this->markTestSkipped('Only one supported locale configured.');
    }

    #[DataProvider('keptTemplates')]
    public function test_kept_template_never_renders_a_raw_storefront_link(string $template): void
    {
        $dir = resource_path('views/store_templates/'.$template);
        if (! is_dir($dir)) {
            $this->markTestSkipped($template.' has no view directory.');
        }

        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($files as $file) {
            if ($file->isDir() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $source = file_get_contents($file->getPathname());

            /* Only URL-EMITTING uses are offences. `@if($banner->link_url)`
               merely asks whether a link exists (themes use it to decide on
               target="_blank"), and flagging that would make the guard
               unfixable. */
            foreach ([
                // A bare publicView route: not locale aware, sends the viewer to the store default.
                "/(?<!storefront_localized_link\()route\('publicView'/",
                // A stored banner/CTA link echoed into markup without localizing.
                '/\{\{[^}]*(?<!storefront_localized_link\()\$(banner|b)->link_url/',
                // …or assigned into a payload the theme's JS turns into an href.
                '/(=>|=)\s*(?<!storefront_localized_link\()\$(banner|b)->link_url/',
            ] as $pattern) {
                if (preg_match($pattern, $source)) {
                    $offenders[] = str_replace(resource_path().'/', '', $file->getPathname());
                    break;
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            $template.' renders a same-site storefront link without storefront_localized_link().',
            'In URL-prefix mode that drops the visitor onto the store default locale.',
            'Wrap it: storefront_localized_link(route(\'publicView\', $store->slug)).',
        ]));
    }

    public static function keptTemplates(): array
    {
        return array_combine(
            self::KEPT,
            array_map(fn ($t) => [$t], self::KEPT)
        );
    }
}
