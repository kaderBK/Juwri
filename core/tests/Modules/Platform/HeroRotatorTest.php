<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Core\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Hero H1 rotating audience noun: the default headline cycles
 * "your restaurant" -> "your store" -> "your café" -> "your hotel"
 * -> "any business" every 3s in JS. The first word is server-rendered
 * inside the <span data-hero-rotator> so the H1 (the LCP element) still
 * paints a complete sentence at FCP; the JS only cycles it afterwards.
 *
 * The :noun template splits cleanly in BOTH directions — the ar value
 * places the noun in a different position than en — and no sentinel or
 * raw :noun placeholder ever leaks into the HTML.
 */
class HeroRotatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);

        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    public function test_hero_h1_renders_rotator_with_five_english_nouns(): void
    {
        app()->setLocale('en');

        $html = $this->get('/')->assertOk()->getContent();

        // Rotating span present; first noun is server-rendered (LCP-safe).
        $this->assertStringContainsString('data-hero-rotator', $html);
        $this->assertStringContainsString('>your restaurant<', $html);

        // Prefix/suffix split correctly around the noun ('&' escaped by Blade).
        $this->assertStringContainsString('QR menu &amp; WhatsApp ordering', $html);
        $this->assertStringContainsString('live in minutes.', $html);

        // All five options live in the data-words payload (café is \u-escaped in JSON).
        foreach (['your restaurant', 'your store', 'your hotel', 'any business', 'your caf'] as $noun) {
            $this->assertStringContainsString($noun, $html, "missing rotator noun: $noun");
        }

        // The sentinel / raw placeholder must never leak into the page.
        $this->assertStringNotContainsString('@@NOUN@@', $html);
        $this->assertStringNotContainsString(':noun', $html);

        // WI-16f: the rotator is driven by the kit marketing script (one file,
        // loaded once): idempotent per headline and still for reduced motion.
        $this->assertSame(1, substr_count($html, 'assets/owner-ui/site.js'), 'site script loaded once');
        $js = file_get_contents(public_path('assets/owner-ui/site.js'));
        $this->assertStringContainsString("querySelectorAll('[data-hero-rotator]')", $js);
        $this->assertStringContainsString('el.dataset.rotating', $js, 'idempotent double-init guard');
        $this->assertStringContainsString('prefers-reduced-motion', $js);
    }

    public function test_hero_h1_splits_for_arabic_word_order(): void
    {
        app()->setLocale('ar');

        $html = $this->get('/')->assertOk()->getContent();

        // ar: prefix — <noun> جاهز خلال دقائق (noun before the tail, no comma).
        $this->assertStringContainsString('data-hero-rotator', $html);
        $this->assertStringContainsString('>مطعمك<', $html);           // first noun, server-rendered
        $this->assertStringContainsString('جاهز خلال دقائق', $html);   // suffix intact
        $this->assertStringNotContainsString('@@NOUN@@', $html);
        $this->assertStringNotContainsString(':noun', $html);
    }
}
