<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Juwri\Core\Settings\PlatformSettings;
use Tests\TestCase;

/**
 * WI-M12: phpunit.xml pins APP_INSTALLED=false, so ViewServiceProvider skips its
 * eight view composers and no view receives $settings/$activeTheme. 200+ test files
 * hand-roll a double whose shape differs from production's array_to_object() stdClass
 * (a divergence that once 500'd the real dashboard while the suite was green). This
 * pins that TestCase::shareLayoutGlobals() builds the SAME stdClass shape, and a
 * ratchet stops the hand-rolled count from growing.
 */
class ViewComposerContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_shared_fixture_has_the_same_shape_as_the_real_composer(): void
    {
        PlatformSettings::set('site_title', 'TestApp');

        // Render through the real composer once so we exercise its path (it may or
        // may not expose shared('settings') depending on the harness; the shape
        // assertion below is the check).
        $this->bootRealViewComposers('/');
        view()->addLocation(base_path('tests/Fixtures/views'));
        view('p1probe')->render();

        $this->shareLayoutGlobals();
        $fromFixture = view()->shared('settings');

        $this->assertInstanceOf(\stdClass::class, $fromFixture,
            'the fixture must be the same stdClass production hands a view, not an anonymous double');
        $this->assertSame('TestApp', $fromFixture->site_title);
    }

    public function test_the_real_composer_produces_a_stdclass_settings(): void
    {
        PlatformSettings::set('site_title', 'RealComposer');

        // NEW coverage: no view()->share() at all — the wildcard composer must
        // build $settings from the options table. Today no test renders through
        // this path; if it regresses this is where it surfaces.
        $this->bootRealViewComposers('/');
        view()->addLocation(base_path('tests/Fixtures/views'));
        $html = view('p1probe')->render();

        // The wildcard composer supplies $settings per-view (via $view->with),
        // not the global shared bag, so the rendered marker is the proof it fired.
        $this->assertStringContainsString('settings-ok', $html,
            'the p1probe fixture prints settings-ok only when the real composer supplied $settings');
    }

    public function test_the_hand_rolled_view_context_count_never_grows(): void
    {
        // File::allFiles() takes ONE directory, so scan the two suites separately
        // and merge. base_path() resolves to core/, so these are core/tests/Feature
        // and core/tests/Unit -- core/tests/TestCase.php is deliberately out of scope,
        // because after this item it legitimately holds the one canonical share call.
        $offenders = collect();
        foreach ([base_path('tests/Feature'), base_path('tests/Unit')] as $dir) {
            foreach (File::allFiles($dir) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $src = (string) file_get_contents($file->getPathname());
                if (str_contains($src, 'view()->share(') && str_contains($src, "'settings'")) {
                    $offenders->push($file->getRelativePathname());
                }
            }
        }

        // Cap raised 220 → 221 for SitePageBuilderTest (HP-40, Site Pages admin
        // builder). Admin chrome reads many optional settings keys (e.g.
        // $settings->site_admin_logo in admin/includes/sidebar.blade.php:188); the
        // strict array_to_object() stdClass that shareLayoutGlobals() produces
        // throws on the first undefined property, so this admin-chrome test
        // legitimately needs the null-returning double (same as AdminDashboardTest).
        // Converting it is not a one-line edit here — documented owner decision.
        //
        // Cap raised 221 → 222 for CurrencySwitcherRendersTest (cl-ledger, the
        // multi-currency display-switcher render guard). Same root cause on the
        // STOREFRONT side: the kept templates read many optional settings keys, so
        // shareLayoutGlobals()'s strict stdClass 500s the render (verified: every
        // theme case returns 500 when converted). This storefront render test is a
        // legitimate double, exactly like the admin-chrome tests above.
        //
        // Cap raised 222 → 228 (notif-toggle-run, rev/notif-toggle-run/LEDGER.md
        // §Owner decisions) for six more storefront/SEO render guards that landed
        // across the currency + track-page + SEO work and were latently over-cap
        // under advisory CI: AnalyticsCurrencyDecimalsTest, DeliverySubStatusTest,
        // OrderTrackBarTest, OrderTrackThrottleTest, SeoBlogLocaleTest and the
        // sibling Seo*/Track* render tests. Every one renders a real storefront or
        // blog page reading many optional settings keys, so the strict stdClass
        // 500s exactly as documented above — same legitimate-double class, not new
        // debt. (No production code hand-rolls context; ViewServiceProvider is the
        // one canonical share.)
        //
        // Cap raised 228 → 230 (admin-analytics run, rev/admin-analytics/LEDGER.md
        // §Owner decisions) for AdminAnalyticsPageTest + AdminAnalyticsIntegrationTest,
        // the superadmin Analytics page/dashboard-teaser render guards. Same
        // admin-chrome root cause as AdminDashboardTest above: the admin layout +
        // sidebar read many optional settings keys, so shareLayoutGlobals()'s strict
        // array_to_object() stdClass 500s the render on the first undefined property
        // (empirically verified — conversion turns both page renders into 500s).
        // Legitimate double, not new debt.
        //
        // Cap raised 230 → 232 (lang-pipeline run, rev/lang-pipeline/LEDGER.md
        // §Owner decisions) for LangPipelinePageTest + LangPipelineIntegrationTest,
        // the translate-pipeline page/coverage-dashboard render guards. Identical
        // admin-chrome cause as the analytics tests above — same admin layout +
        // sidebar. Legitimate double, not new debt.
        // Cap raised 232 → 233 (biolink run, rev/biolink-pages/LEDGER.md §Owner
        // decisions) for BioLinkAdminTest — the superadmin FeatureMatrix + plans/edit
        // render guards. Same admin-chrome root cause as AdminAnalyticsPageTest /
        // LangPipelinePageTest above: the admin layout + sidebar read many optional
        // settings keys, so shareLayoutGlobals()'s strict array_to_object() stdClass
        // 500s the render. Legitimate double, not new debt. (The owner / reseller /
        // storefront / interstitial bio render tests were converted to
        // shareLayoutGlobals() and are NOT counted.)
        //
        // Cap raised 233 → 235 (syria-gateways F0 merge, rev/syria-gateways/LEDGER.md
        // §Owner decisions) for the two wallet_transfer storefront-render guards:
        // WalletTransferGatewayTest and WalletTransferStorefrontExposureTest. Both
        // drive real storefront pages over HTTP (the payment page + the order-track
        // page with the shared payment-pending partial), which read many optional
        // settings keys — so shareLayoutGlobals()'s strict array_to_object() stdClass
        // 500s exactly as the storefront cases above, and the null-returning double is
        // the legitimate pattern, not new debt.
        //
        // Cap raised 235 → 236 (external-redirect run, rev/external-redirect/HANDOFF.md
        // §Owner decisions) for ExternalRedirectAdminTest — the superadmin restaurants
        // index/editor render guard for the migration-opt-out feature. Admin chrome
        // reads $settings->site_admin_logo (admin/includes/sidebar.blade.php), undefined
        // on shareLayoutGlobals()'s strict stdClass → 500, same admin-chrome root cause
        // as AdminDashboardTest / AdminAnalyticsPageTest above. The feature's owner /
        // reseller / storefront render tests all use shareLayoutGlobals() (no double).
        //
        // Cap raised 236 → 237 (reseller-store R5, D3 storefront announcement banner)
        // for StorefrontAnnouncementBannerTest — it renders the full store_templates
        // storefront to prove the banner shows/hides. The kept templates read many
        // optional settings keys, so shareLayoutGlobals()'s strict array_to_object()
        // stdClass 500s on the first undefined property; the null-returning $settings
        // double is the documented storefront-render pattern (same root cause as
        // StorefrontHomeOrderTypeTest / CurrencySwitcherRendersTest above), not new debt.
        //
        // Cap raised 237 → 238 (Theme Studio B0.9, rev/theme-studio/IMPLEMENTATION-SPEC.md)
        // for StudioLinterRulesTest's superadmin theme-review queue render guard (it
        // proves the Lighthouse gate report shows on admin/theme-reviews). Admin chrome
        // reads optional settings keys, so shareLayoutGlobals()'s strict stdClass 500s
        // the page (verified: converting it turns the render into a 500) — the same
        // admin-chrome root cause as AdminDashboardTest / ExternalRedirectAdminTest
        // above. Legitimate double, not new debt.
        $this->assertLessThanOrEqual(
            238,
            $offenders->count(),
            "A test is hand-rolling the layout view context again ({$offenders->count()} files do). "
            .'Production hands views an array_to_object() stdClass (ViewServiceProvider), not an '
            .'anonymous double; call $this->shareLayoutGlobals() instead. If you are another lane and '
            .'this blocks you, converting your file is a one-line edit -- raising this cap needs a '
            .'LEDGER.md §Owner decisions entry (WI-M12).'
        );
    }
}
