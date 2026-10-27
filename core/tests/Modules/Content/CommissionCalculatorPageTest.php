<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Core\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Free public tool: /tools/commission-calculator (SEO run W2.1).
 *
 * HTTP-through-controller (blade-direct render() misses the controller
 * contract). Asserts the tool UI markers, the KEYWORDS §Commissions preset
 * table server-side, and that the page indexes (no noindex on a money tool).
 */
class CommissionCalculatorPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);

        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    public function test_page_renders_through_controller_with_tool_markers(): void
    {
        $response = $this->get(route('tools.commission-calculator'));

        $response->assertOk();
        // H1 keyword.
        $response->assertSee(___('Delivery Commission Calculator'));
        // Interactive tool markers (not a static marketing page).
        $response->assertSeeHtml('data-cc-root');
        $response->assertSeeHtml('data-cc-market');
        $response->assertSeeHtml('commission-calc.js');
        // Aggregator proper nouns from the preset table.
        $response->assertSeeHtml('Yemeksepeti');
        $response->assertSeeHtml('Talabat');
        $response->assertSeeHtml('iFood');

        $html = $response->getContent();
        // A money tool must index — never noindex.
        $this->assertStringNotContainsStringIgnoringCase('noindex', $html);
        // Dead spacing utilities / missing helper class must not creep in.
        $this->assertStringNotContainsString('mt-5', $html);
        $this->assertStringNotContainsString('mt-4', $html);
        $this->assertStringNotContainsString('visually-hidden', $html);
    }

    public function test_title_matches_keywords_payload(): void
    {
        $html = $this->get(route('tools.commission-calculator'))->getContent();

        // KEYWORDS §Calculator (en) verbatim, brand appended per the home shape.
        $this->assertStringContainsString(
            '<title>Delivery Commission Calculator — What Do Aggregators Really Cost You? | ',
            $html
        );
    }

    public function test_preset_table_matches_keywords_commission_data(): void
    {
        $defs = require base_path('resources/views/marketing/data/commission-defs.php');

        // The seven KEYWORDS §Commissions markets must all be present.
        foreach (['tr', 'br', 'de', 'fr', 'sa', 'ae', 'pk', 'in'] as $cc) {
            $this->assertArrayHasKey($cc, $defs, "missing market [$cc]");
            $this->assertArrayHasKey('rate', $defs[$cc]);
            $this->assertArrayHasKey('rate_label', $defs[$cc]);
            $this->assertGreaterThan(0, $defs[$cc]['rate']);
            $this->assertLessThanOrEqual(1, $defs[$cc]['rate']);
        }

        // Rates + labels are the published take rates from KEYWORDS.md verbatim.
        $this->assertSame('Yemeksepeti', $defs['tr']['aggregator']);
        $this->assertSame('15–40%', $defs['tr']['rate_label']);
        $this->assertSame(0.40, $defs['tr']['rate']);
        $this->assertSame('iFood', $defs['br']['aggregator']);
        $this->assertSame('~23–27%', $defs['br']['rate_label']);
        $this->assertSame('Talabat', $defs['sa']['aggregator']);
        $this->assertSame('foodpanda', $defs['pk']['aggregator']);

        // The client-side formula the JS applies (orders × ticket × rate),
        // asserted server-side against the shipped presets.
        $orders = 700;
        $ticket = 250;
        $monthly = $orders * $ticket * $defs['tr']['rate'];
        $this->assertEqualsWithDelta(70000.0, $monthly, 0.001);
        $this->assertEqualsWithDelta(840000.0, $monthly * 12, 0.001);
    }

    public function test_ar_renders_rtl_with_valid_data_island(): void
    {
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);
        app()->setLocale('ar');

        $html = $this->get(route('tools.commission-calculator'))->getContent();

        $this->assertStringContainsString('dir="rtl"', $html, 'AR must render right-to-left');
        // Arabic H1 (canonical hamza-free spelling per KEYWORDS rules).
        $this->assertStringContainsString('حاسبة عمولة التوصيل', $html);

        // The JSON data island must survive encoding as valid, parseable JSON.
        $this->assertMatchesRegularExpression('/data-cc-data[^>]*>(.*?)<\/script>/s', $html);
        preg_match('/data-cc-data[^>]*>(.*?)<\/script>/s', $html, $m);
        $data = json_decode(trim($m[1]), true);
        $this->assertIsArray($data);
        $this->assertSame('Yemeksepeti', $data['tr']['aggregator']);
        $this->assertSame(0.40, $data['tr']['rate']);
    }

    public function test_localized_strings_seeded_for_all_locales(): void
    {
        // The i18n contract: every new ___() key hand-seeded in all 11 locales.
        foreach (['en', 'ar', 'de', 'es', 'fr', 'pt', 'ru', 'tr', 'zh', 'hi', 'ur'] as $locale) {
            $lang = require base_path("lang/$locale/lang.php");
            $this->assertArrayHasKey('your_estimated_commission', $lang, "[$locale] missing calculator key");
            $this->assertNotSame('', trim((string) $lang['your_estimated_commission']));
            if ($locale !== 'en') {
                // Non-en must be a real translation, not the English source.
                $this->assertNotSame('Your estimated commission', $lang['your_estimated_commission'], "[$locale] left untranslated");
            }
        }
    }
}
