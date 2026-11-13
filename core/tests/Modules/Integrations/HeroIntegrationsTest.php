<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * Home hero feature badges + the integration-logo pipeline.
 *
 * Before WI-16 the legacy hero (templates/lamar/home/partials/landing/section-hero)
 * floated 14 localized feature badges with integration logos from the theme's
 * img/integrations folder. The kit home hero (marketing/sections/hero) shows the
 * same idea as a chip row: every chip is a localized label with a kit icon, and
 * no payment gateway is advertised. integration_logo() now looks in
 * assets/site/integrations/ (nothing ships there since WI-16h) and returns null,
 * so callers fall back to an icon.
 */
class HeroIntegrationsTest extends TestCase
{
    private const PAYMENT_BRANDS = ['stripe', 'paypal', 'razorpay', 'paytabs', 'paystack', 'midtrans', 'ccavenue', 'paytm', 'mollie', 'paddle', 'tap', 'tabby', 'tamara'];

    private function hero(): string
    {
        return (string) file_get_contents(resource_path('views/marketing/sections/hero.blade.php'));
    }

    /** @return list<array{0: string, 1: string}> [icon, label source] per chip */
    private function chips(): array
    {
        preg_match('/\$mkChips\s*=\s*\[(.*?)\];/s', $this->hero(), $m);
        $this->assertNotEmpty($m, '$mkChips array present in the kit hero');
        preg_match_all("/\['([a-z-]+)',\s*(___\('[^']+'\))\]/", $m[1], $rows, PREG_SET_ORDER);

        return array_map(fn ($r) => [$r[1], $r[2]], $rows);
    }

    public function test_hero_feature_badges_are_localized_and_each_has_an_icon(): void
    {
        $chips = $this->chips();
        preg_match('/\$mkChips\s*=\s*\[(.*?)\];/s', $this->hero(), $m);
        $this->assertGreaterThanOrEqual(8, count($chips), 'hero shows a full row of feature badges');
        $this->assertSame(count($chips), substr_count($m[1], '___('), 'every badge label is localized');
        $this->assertStringContainsString('<x-ui.icon :name="$mkIcon"', $this->hero(), 'badges render a kit icon');

        foreach ($chips as [$icon, $label]) {
            $svg = Blade::render('<x-ui.icon :name="$n" />', ['n' => $icon]);
            $this->assertStringContainsString('<svg', $svg, "badge $label: icon '$icon' renders");
        }
    }

    public function test_no_payment_gateways_in_hero_integrations_or_logo_folder(): void
    {
        $hero = strtolower($this->hero());
        $integrations = strtolower((string) file_get_contents(resource_path('views/site/blocks/section_integrations.blade.php')));
        $dir = dirname(base_path()).'/assets/site/integrations';
        foreach (self::PAYMENT_BRANDS as $banned) {
            $this->assertDoesNotMatchRegularExpression('/\b'.$banned.'\b/', $hero, "no payment ref in hero: $banned");
            $this->assertDoesNotMatchRegularExpression('/\b'.$banned.'\b/', $integrations, "no payment ref in integrations block: $banned");
            $this->assertEmpty(glob($dir.'/'.$banned.'.*') ?: [], "no payment logo file: $banned");
        }
    }

    public function test_logo_pipeline_resolves_a_dropped_logo_and_falls_back_to_null(): void
    {
        $this->assertNull(integration_logo('nope-not-a-partner'), 'absent key falls back to icon');
        $this->assertNull(integration_logo(null), 'null key safe');
        $this->assertNull(integration_logo(''), 'empty key safe');

        // A brand-kit file dropped into the folder is picked up.
        $dir = dirname(base_path()).'/assets/site/integrations';
        $hadDir = is_dir($dir);
        $key = 'wi16probe'.getmypid();
        @mkdir($dir, 0777, true);
        file_put_contents("$dir/$key.svg", '<svg xmlns="http://www.w3.org/2000/svg"/>');
        try {
            $this->assertSame('/assets/site/integrations/'.$key.'.svg', integration_logo($key));
        } finally {
            @unlink("$dir/$key.svg");
            if (! $hadDir) {
                @rmdir($dir);
            }
        }
    }
}
