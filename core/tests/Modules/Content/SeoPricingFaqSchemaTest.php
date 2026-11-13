<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Juwri\Core\Models\Language;
use Juwri\Modules\Content\Models\Faq;
use Tests\Support\TestPlans;
use Tests\TestCase;

class SeoPricingFaqSchemaTest extends TestCase
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
        config(['settings.enable_faqs' => 1, 'settings.currency_code' => 'USD']);

        $settings = new class(['theme_color' => '#111', 'site_title' => 'Tag | Brand', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
        {
            public function __construct(private array $data) {}

            public function __get(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function __set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function __isset(string $key): bool
            {
                return array_key_exists($key, $this->data);
            }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect(), 'pages' => collect()]);
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function jsonLdOfType(string $html, string $type): ?array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        foreach ($m[1] as $json) {
            $d = json_decode($json, true);
            $this->assertNotNull($d, 'every JSON-LD block parses');
            if (($d['@type'] ?? '') === $type) {
                return $d;
            }
        }

        return null;
    }

    public function test_pricing_product_offers_match_active_plans(): void
    {
        $this->pitchOrSkip();
        $this->requiresEditionPackage('behaviour of an edition package (plans, billing, reseller or marketplace)');
        TestPlans::create(['name' => 'Starter', 'description' => 'T', 'monthly_price' => 5, 'annual_price' => 50, 'lifetime_price' => 500, 'settings' => json_encode([]), 'status' => 1]);
        TestPlans::create(['name' => 'Pro', 'description' => 'T', 'monthly_price' => 15, 'annual_price' => 150, 'lifetime_price' => 1500, 'settings' => json_encode([]), 'status' => 1]);
        TestPlans::create(['name' => 'Hidden', 'description' => 'T', 'monthly_price' => 99, 'annual_price' => 990, 'lifetime_price' => 9900, 'settings' => json_encode([]), 'status' => 0]);

        $html = $this->get('/pricing')->assertOk()->getContent();
        // SaaS pricing is a SoftwareApplication, not a retail Product — a Product
        // triggered GSC merchant-listing warnings for physical-only fields.
        $app = $this->jsonLdOfType($html, 'SoftwareApplication');

        $this->assertNotNull($app, 'SoftwareApplication schema present');
        $this->assertNull($this->jsonLdOfType($html, 'Product'), 'no retail Product node');
        $this->assertSame('BusinessApplication', $app['applicationCategory']);
        $this->assertCount(2, $app['offers'], 'only active plans');
        $byName = collect($app['offers'])->keyBy('name');
        $this->assertSame('5.00', $byName['Starter']['price']);
        $this->assertSame('15.00', $byName['Pro']['price']);
        $this->assertSame('USD', $byName['Pro']['priceCurrency']);
        $this->assertArrayNotHasKey('Hidden', $byName->all());
    }

    public function test_faq_page_schema_matches_rendered_faqs(): void
    {
        Faq::create(['faq_title' => 'How fast is setup?', 'faq_content' => '<p>Under <b>five</b> minutes.</p>', 'translation_lang' => null, 'active' => 1]);
        Faq::create(['faq_title' => 'Hidden question', 'faq_content' => 'x', 'translation_lang' => null, 'active' => 0]);

        $html = $this->get('/faq')->assertOk()->getContent();
        $faq = $this->jsonLdOfType($html, 'FAQPage');

        $this->assertNotNull($faq, 'FAQPage schema present');
        $this->assertCount(1, $faq['mainEntity'], 'inactive faqs excluded');
        $this->assertSame('How fast is setup?', $faq['mainEntity'][0]['name']);
        $this->assertSame('Under five minutes.', $faq['mainEntity'][0]['acceptedAnswer']['text'], 'answer is tag-stripped');
    }

    private function pitchOrSkip(): void
    {
        if (! Route::has('pricing') && ! Route::has('guides.show')) {
            $this->markTestSkipped('pitch routes belong to the edition package');
        }
        config(['settings.platform_marketing' => true]);
    }
}
