<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Models\MenuBanner;
use Juwri\Modules\Storefront\Storefront\Drops\BannerDrop;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;
use Tests\TestCase;

/**
 * Liquid partner themes must not be able to drop a diner onto the wrong locale.
 *
 * Same bug the Blade themes had ("clicking an item on soiree changed the
 * language"): a menu banner stores the URL the owner typed, which is nearly
 * always unprefixed, and in URL-prefix mode an unprefixed URL IS the
 * default-locale page.
 *
 * For Liquid the fix has to live in the DROP rather than in the template: a
 * partner theme is third-party code, `{{ banner.link_url }}` is the obvious
 * thing to write, and neither the filter whitelist nor ThemeLinter can inspect
 * a runtime value. Localizing inside BannerDrop::link_url() means every theme
 * gets it right without knowing the rule exists.
 */
class LiquidBannerLinkLocaleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.include_language_code' => true]);
    }

    private function useLocale(string $locale): void
    {
        app()->setLocale($locale);
        LaravelLocalization::setLocale($locale);
    }

    /** The locale that carries a prefix in this environment. */
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

    private function drop(?string $link): BannerDrop
    {
        $banner = new MenuBanner;
        $banner->id = 1;
        $banner->title = 'Promo';
        $banner->link_url = $link;

        return new BannerDrop($banner);
    }

    public function test_theme_reads_a_localized_link_without_asking_for_it(): void
    {
        $locale = $this->nonDefaultLocale();
        $this->useLocale($locale);

        $this->assertSame(
            url('/'.$locale.'/soiree-demo').'?item=65556',
            $this->drop(url('/soiree-demo').'?item=65556')->link_url()
        );
    }

    public function test_external_and_empty_links_are_untouched(): void
    {
        $this->useLocale($this->nonDefaultLocale());

        $this->assertSame('https://instagram.com/soiree', $this->drop('https://instagram.com/soiree')->link_url());
        $this->assertNull($this->drop(null)->link_url());
    }

    public function test_default_locale_stays_unprefixed(): void
    {
        $default = LaravelLocalization::getDefaultLocale();
        $this->useLocale($default);

        $this->assertStringNotContainsString(
            '/'.$default.'/soiree-demo',
            (string) $this->drop(url('/soiree-demo'))->link_url()
        );
    }

    public function test_session_mode_serves_one_url_per_page(): void
    {
        config(['settings.include_language_code' => false]);
        $this->useLocale($this->nonDefaultLocale());

        $url = url('/soiree-demo').'?item=1';
        $this->assertSame($url, $this->drop($url)->link_url());
    }
}
