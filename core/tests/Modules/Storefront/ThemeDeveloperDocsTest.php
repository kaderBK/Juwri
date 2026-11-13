<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Controllers\DeveloperDocsController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Modules\Storefront\Services\Rendering\LiquidEnvironmentFactory;
use Juwri\Modules\Storefront\Services\Rendering\LiquidRenderer;
use Juwri\Modules\Storefront\Services\Rendering\RenderClient;
use Juwri\Modules\Storefront\Storefront\Drops\FlagsDrop;
use Juwri\Modules\Storefront\Storefront\MarketplaceThemeResolver;
use Juwri\Modules\Storefront\Storefront\SectionedTemplate;
use Juwri\Modules\Storefront\Storefront\ThemePackageValidator;
use Tests\TestCase;

/**
 * P2-7: theme developer docs — the Drop/filter references are generated from
 * the real classes (no drift), and the downloadable starter theme is a valid,
 * renderable example of the documented format.
 */
class ThemeDeveloperDocsTest extends TestCase
{
    use RefreshDatabase;

    public function test_docs_page_serves_generated_references(): void
    {
        // Doc prose is now translatable (___()); default test locale is ar, so pin
        // English to assert the English copy + the reflected Drop/filter references.
        $html = $this->withSession(['locale' => 'en'])->get(route('developers.themes'))->assertOk()->getContent();

        // Drop reference is reflected from the classes — spot-check reals.
        $this->assertStringContainsString('scans_today', $html);
        $this->assertStringContainsString('accepts_orders', $html);
        $this->assertStringContainsString('StoreDrop', $html);
        // Filter whitelist reflected from ThemeFilters.
        foreach (['media_url', 'money', 'money_code', 't', 'theme_asset'] as $filter) {
            $this->assertStringContainsString($filter, $html);
        }
        // Core format facts present.
        $this->assertStringContainsString('25 sections', $html);
        $this->assertStringContainsString('50 blocks', $html);
        $this->assertStringContainsString('data-mb-add', $html);
    }

    public function test_drop_reference_excludes_nothing_it_should_and_leaks_nothing(): void
    {
        $drops = DeveloperDocsController::dropReference();

        $this->assertArrayHasKey('CustomerDrop', $drops);
        $names = array_column($drops['CustomerDrop'], 'name');
        sort($names);
        // The reference documents EXACTLY the exposed surface: name + phone, plus
        // the authed diner's OWN store-credit balance (P7-5a, account-bound D3).
        // Still never id/email/tokens/etc.
        $this->assertSame(['name', 'phone', 'store_credit', 'store_credit_formatted'], $names);
    }

    public function test_starter_zip_downloads_and_passes_the_package_validator(): void
    {
        $resp = $this->get(route('developers.themes.starter'));
        $resp->assertOk();
        $this->assertSame('application/zip', $resp->headers->get('content-type'));

        // Round-trip: what we serve must be installable by our own rules.
        // (download() returns a BinaryFileResponse — copy before it's cleaned up.)
        $tmp = storage_path('app/starter-roundtrip.zip');
        copy($resp->baseResponse->getFile()->getPathname(), $tmp);
        try {
            $manifest = ThemePackageValidator::assertSafe($tmp);
            $this->assertSame('starter', $manifest['slug']);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_starter_theme_renders_through_the_sectioned_engine(): void
    {
        $theme = MarketplaceThemeResolver::resolve('mp:starter@1');
        $this->assertNotNull($theme, 'in-repo starter resolves');
        $this->assertTrue($theme->isSectioned());

        $engine = new SectionedTemplate(new LiquidRenderer(new LiquidEnvironmentFactory, new RenderClient));
        $out = $engine->render($theme, [
            'flags' => new FlagsDrop(['allow_order' => true]),
        ]);

        $this->assertStringContainsString('mb-section-hero', $out);
        $this->assertStringContainsString('mb-section-menu', $out);
        $this->assertStringContainsString('data-mb-open-cart', $out);
    }
}
