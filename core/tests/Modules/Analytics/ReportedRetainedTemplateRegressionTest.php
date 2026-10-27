<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Analytics;

use Tests\TestCase;

class ReportedRetainedTemplateRegressionTest extends TestCase
{
    public function test_reported_storefronts_keep_language_account_and_order_history_contracts(): void
    {
        $orbit = file_get_contents(public_path('assets/store_templates/orbit/js/script.js'));
        $velora = file_get_contents(public_path('assets/store_templates/velora/js/script.js'));
        $crumb = file_get_contents(public_path('assets/store_templates/crumb/js/script.js'));

        foreach ([$orbit, $velora] as $script) {
            $this->assertStringContainsString('navigateLanguage(', $script);
            $this->assertStringContainsString('rememberRecentOrder(res)', $script);
            $this->assertStringContainsString('renderRecentOrders()', $script);
            $this->assertStringContainsString('product-reviews', $script);
            $this->assertStringContainsString('product-home-link', $script);
        }

        $this->assertStringContainsString('store.set("orders", S.orders)', $crumb);
        $this->assertStringContainsString('trackUrl: json.track_url', $crumb);
        $this->assertStringContainsString('data-address=', $crumb);
        $this->assertStringContainsString('json.order_id', $crumb);
        $this->assertStringContainsString('crumbAuthPost(', $crumb);
        $this->assertStringContainsString('renderBanners()', $crumb);
        $this->assertStringContainsString('renderExtras(S.currentItem);', $crumb);
        $this->assertStringContainsString('C.endpoints.orderLookup', $crumb);
        $this->assertStringContainsString('C.endpoints.geoAutocomplete', $crumb);
        $this->assertStringContainsString('C.endpoints.geoReverse', $crumb);
        // The reel CTA still hard-navigates, but through the shared locale
        // helper now — a prefix-less cta_url dropped /en/ visitors onto the
        // default-language page (d04e0d851).
        $this->assertStringContainsString('location.assign(href)', $crumb);
        $this->assertStringContainsString('mbStoryCta.localizeSameOrigin', $crumb);
        $this->assertStringContainsString('document.querySelector(".view.is-active")?.dataset.view || "home"', $crumb);
    }

    public function test_demo_fixtures_do_not_expire_features_or_disable_customer_accounts(): void
    {
        $seeder = file_get_contents(app_path('Modules/Storefront/Database/Seeders/ThemeShowcaseSeeder.php'));

        $this->assertGreaterThanOrEqual(2, substr_count($seeder, "'customer_auth_mode' => 'optional'"));
        $this->assertStringContainsString("'expires_at' => now()->addYears(5)", $seeder);
        // Banners still carry a real (non-dead) link — now a deep-link to a
        // featured dish (/{slug}?item=…) via $itemLink rather than the store home.
        $this->assertStringContainsString("'link_url' => \$itemLink(", $seeder);
    }

    public function test_reported_theme_views_expose_home_preferences_and_crumb_authentication(): void
    {
        foreach (['orbit', 'velora', 'crumb'] as $theme) {
            $view = file_get_contents(resource_path("views/store_templates/{$theme}/index.blade.php"));
            $this->assertStringContainsString('home-order-types', $view, "{$theme} must expose order type on home");
        }

        $crumb = file_get_contents(resource_path('views/store_templates/crumb/index.blade.php'));
        $velora = file_get_contents(resource_path('views/store_templates/velora/index.blade.php'));
        $this->assertStringContainsString('class="home-address"', $velora);
        $this->assertStringContainsString('id="homeLangBtn"', $crumb);
        $this->assertStringContainsString('id="crumbAuthBtn"', $crumb);
        // The header profile/auth button was removed from .bh-top; the account +
        // auth entry point from home is now the Profile tab in the bottom nav.
        $this->assertStringContainsString('data-tab="profile"', $crumb);
        $this->assertStringContainsString('id="homeLangSheet"', $crumb);
        $this->assertStringContainsString('id="locGeoAttrib"', $crumb);
        $this->assertStringContainsString('id="crumbRegisterPasswordConfirm"', $crumb);
        $this->assertStringContainsString('id="lookupInput"', $crumb);
        $this->assertStringContainsString('id="lookupBtn"', $crumb);
        $this->assertStringContainsString('orderLookup:', $crumb);
        $this->assertStringContainsString('customerRegister:', $crumb);
        $this->assertStringContainsString('id="crumbBanners"', $crumb);
        $this->assertStringContainsString('<div class="app crumb"', $crumb);

        $track = file_get_contents(resource_path('views/store_templates/crumb/track.blade.php'));
        $this->assertStringNotContainsString('data-back href="{{ route(\'publicView\', $store->slug) }}"', $track);
        $this->assertStringContainsString('<div class="app crumb track-app"', $track);
        $this->assertStringContainsString('id="crumbTrackHomeTop"', $track);
        $this->assertStringContainsString('id="crumbTrackHomeBottom"', $track);

        $styles = file_get_contents(public_path('assets/store_templates/crumb/css/style.css'));
        $this->assertStringContainsString('.crumb .cat-tile i img', $styles);
        $this->assertStringContainsString('.crumb .branch-cards', $styles);
    }
}
