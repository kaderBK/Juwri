<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Services\Theme\KeptTemplates;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * The shared storefront info-page footer (_partials/info-links): Terms / Privacy
 * / About / Help links surfaced on the MENU for every visitor, not only on the
 * logged-in profile page.
 *
 * The links themselves are public routes that already existed; what this pins is
 * the discovery surface — it must appear when (and only when) the owner has
 * written at least one page, must carry a real link to each filled page, and
 * must stay off the ~40 legacy templates the same way the offers strip does.
 *
 * Placement (bottom of the menu, clearing the dock) is a browser fact verified
 * in Chrome, not here — a server render cannot tell a placed footer from one
 * still parked and hidden at the top of <main>.
 */
class StorefrontInfoLinksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        // The canonical layout context (WI-M12) rather than a hand-rolled double,
        // so this file stays out of ViewComposerContractTest's offender count.
        $this->shareLayoutGlobals();

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function storeOnTheme(string $theme = 'matcha'): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);
        StoreOption::updateStoreOption($store->id, 'timezone', 'UTC');
        StoreOption::updateStoreOption($store->id, 'store_template', $theme);

        return $store;
    }

    // ---------------------------------------------------------------- positive

    public function test_a_filled_page_surfaces_the_footer_with_a_real_link(): void
    {
        $store = $this->storeOnTheme();
        StoreOption::updateStoreOption($store->id, 'sf_page_terms', 'Our terms.');

        $html = $this->get('/'.$store->slug)->assertOk()->getContent();

        $this->assertStringContainsString('id="mbInfoFoot"', $html);
        $this->assertStringContainsString('/'.$store->id.'/page/terms', $html);
        // Only the filled page links; an unfilled one must not appear.
        $this->assertStringNotContainsString('/'.$store->id.'/page/privacy', $html);
    }

    /** Shared, not per-theme: every retained template gets it. */
    public function test_the_footer_renders_on_every_retained_template(): void
    {
        foreach (KeptTemplates::MODERN as $theme) {
            $store = $this->storeOnTheme($theme);
            StoreOption::updateStoreOption($store->id, 'sf_page_about', 'About us.');

            $html = $this->get('/'.$store->slug)->assertOk()->getContent();
            $this->assertStringContainsString('id="mbInfoFoot"', $html, "$theme should render the info footer");
            $this->assertStringContainsString('/'.$store->id.'/page/about', $html, "$theme should link the filled page");
        }
    }

    // ---------------------------------------------------------------- negative

    public function test_no_filled_pages_renders_only_the_report_link(): void
    {
        $store = $this->storeOnTheme();
        // Owner wrote nothing: A4 still gives every modern store a "Report this store" link.

        $html = $this->get('/'.$store->slug)->assertOk()->getContent();

        $this->assertStringContainsString('id="mbInfoFoot"', $html);
        $this->assertStringContainsString('Report this store', $html);
        $this->assertStringContainsString('report='.$store->slug, $html);
        $this->assertStringNotContainsString('/page/', $html, 'no info-page links when none are filled');
    }

    public function test_a_whitespace_only_page_is_treated_as_empty(): void
    {
        $store = $this->storeOnTheme();
        StoreOption::updateStoreOption($store->id, 'sf_page_terms', "   \n  ");

        $html = $this->get('/'.$store->slug)->assertOk()->getContent();

        $this->assertStringNotContainsString('/'.$store->id.'/page/terms', $html);
    }

    /**
     * WI-03: the legacy templates were removed; a store still saved on one
     * renders the fallback template, footer included.
     */
    public function test_store_on_a_removed_template_gets_the_footer_via_the_fallback(): void
    {
        $store = $this->storeOnTheme('old-2-theme');
        StoreOption::updateStoreOption($store->id, 'sf_page_terms', 'Our terms.');

        $html = $this->get('/'.$store->slug)->assertOk()->getContent();

        $this->assertStringContainsString('mbInfoFoot', $html);
    }
}
