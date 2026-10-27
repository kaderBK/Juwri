<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Core\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * WI-16f: the marketing home, pricing and FAQ pages render on the owner-ui kit
 * frame (marketing/layout) with no bought-theme stylesheet or script, prices
 * come from the plan table, and no hard-coded support phone or third-party chat
 * widget is left in the marketing frame.
 */
class MarketingKitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function plan(string $name, float $monthly, float $annual = 0, string $recommended = 'no'): object
    {
        return TestPlans::create([
            'name' => $name, 'description' => $name.' plan', 'monthly_price' => $monthly, 'annual_price' => $annual,
            'lifetime_price' => 0, 'recommended' => $recommended, 'status' => 1, 'settings' => [],
        ]);
    }

    public function test_home_pricing_and_faq_render_on_the_kit_frame(): void
    {
        $this->plan('Starter Kit Plan', 9, 90);

        foreach (['/', '/pricing', '/faq'] as $path) {
            if ($path === '/faq') {
                config(['settings.enable_faqs' => 1]);
            }
            $html = $this->get($path)->assertOk()->getContent();

            $this->assertStringContainsString('class="mk-top"', $html, "$path: kit top bar");
            $this->assertStringContainsString('class="mk-foot"', $html, "$path: kit footer");
            $this->assertStringContainsString('assets/owner-ui/site.css', $html, $path);
            $this->assertStringContainsString('assets/owner-ui/site.js', $html, $path);
            $this->assertStringNotContainsString('landing/assets/css/style', $html, "$path must not load the old landing CSS");
            $this->assertStringNotContainsString('landing/assets/js/main.js', $html, "$path must not load the old landing JS");
            $this->assertStringNotContainsString('mk-body--legacy', $html, $path);
        }
    }

    public function test_pricing_cards_read_the_plan_table(): void
    {
        $this->requiresEditionPackage('behaviour of an edition package (plans, billing, reseller or marketplace)');
        $pro = $this->plan('Pro Kit Plan', 29, 290, 'yes');
        $this->plan('Basic Kit Plan', 9);

        $html = $this->get('/pricing')->assertOk()->getContent();

        $this->assertStringContainsString('Pro Kit Plan', $html);
        $this->assertStringContainsString('Basic Kit Plan', $html);
        $this->assertStringContainsString('pricing-card-b--rec', $html);
        $this->assertStringContainsString('data-cycle="yearly"', $html);
        $this->assertStringContainsString('class="mk-pricing"', $html);
        $this->assertStringContainsString(route('register').'?plan='.$pro->id, $html);
        $this->assertStringContainsString('class="pricing-card-b__save"', $html, '29/mo vs 290/yr shows the yearly saving');
        $this->assertStringContainsString('data-yearly-price="290', $html);
    }

    public function test_marketing_frame_has_no_hard_coded_contact_or_chat_widget(): void
    {
        $frame = '';
        foreach (glob(resource_path('views/marketing/{,partials/,sections/}*.blade.php'), GLOB_BRACE) as $file) {
            // the SPDX licence header (a Blade comment) names the company, it is not visible markup
            $frame .= preg_replace('/\A(\{\{-- (?:SPDX-License-Identifier|Copyright)[^\n]*--\}\}\n)+/', '', (string) file_get_contents($file));
        }

        $this->assertDoesNotMatchRegularExpression('/\b\d{10,}\b/', $frame, 'no literal phone numbers in marketing markup');
        $this->assertStringNotContainsString('wati', strtolower($frame));
        // P1-08: PHP class names (\Juwri\Core\…) in @php blocks are code, not visible brand text
        $visible = (string) preg_replace('/\\\\?Juwri(\\\\[A-Za-z_]+)+/', '', str_replace('work-for-juwri', '', $frame));
        $this->assertStringNotContainsString('juwri', strtolower($visible));
    }

    public function test_support_chat_link_only_appears_when_the_platform_sets_a_number(): void
    {
        $this->assertStringNotContainsString('class="mk-support"', $this->get('/')->getContent());

        $this->shareLayoutGlobals(['support_whatsapp' => '+1 555 0100']);
        $html = $this->get('/')->getContent();
        $this->assertStringContainsString('class="mk-support"', $html);
        $this->assertStringContainsString('https://wa.me/15550100', $html);
    }

    public function test_rebuilt_landing_pages_load_no_old_body_assets(): void
    {
        // /features used to be the legacy-body example; it is now a kit page
        // (WI-16f-2), so the old landing stylesheet and scripts stay out.
        $html = $this->get('/features')->assertOk()->getContent();

        $this->assertStringContainsString('class="mk-top"', $html);
        $this->assertStringNotContainsString('mk-body--legacy', $html);
        $this->assertStringNotContainsString('landing/assets/js/main.js', $html);
    }
}
