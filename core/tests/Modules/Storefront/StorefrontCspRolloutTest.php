<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use App\Support\Csp\StorefrontPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Security A2 (upstream 855066828): the storefront CSP can be
 * enforced theme by theme (CSP_ENFORCE_TEMPLATES), and the analytics a store
 * really uses keeps working under it.
 */
class StorefrontCspRolloutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function store(string $theme): Store
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999])]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create(['user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(8)), 'color' => '#111', 'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123']);
        StoreOption::updateStoreOption($store->id, 'timezone', 'UTC');
        StoreOption::updateStoreOption($store->id, 'store_template', $theme);

        return $store;
    }

    public function test_listed_theme_gets_the_enforcing_header_others_stay_report_only(): void
    {
        // the per-theme roll-out under the roll-back switches (enforcing is the default since the P4-17 follow-up)
        config(['csp.enforce' => false, 'csp.policy' => '', 'csp.report_only_policy' => StorefrontPolicy::class,
            'csp.storefront_enforce' => 'store_hosts', 'csp.enforce_templates' => ['matcha']]);
        $enforced = $this->get('/'.$this->store('matcha')->slug)->assertOk();
        $this->assertNotEmpty($enforced->headers->get('Content-Security-Policy'));
        $this->assertNull($enforced->headers->get('Content-Security-Policy-Report-Only'));
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-([^']+)'/", $enforced->headers->get('Content-Security-Policy'));

        $other = $this->get('/'.$this->store('crave')->slug)->assertOk();
        $this->assertNull($other->headers->get('Content-Security-Policy'));
        $this->assertNotEmpty($other->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function test_store_analytics_hosts_are_allowed_only_when_configured(): void
    {
        $plain = $this->store('matcha');
        $header = fn ($r) => (string) ($r->headers->get('Content-Security-Policy') ?: $r->headers->get('Content-Security-Policy-Report-Only'));
        $csp = $header($this->get('/'.$plain->slug));
        $this->assertDoesNotMatchRegularExpression('#script-src [^;]*googletagmanager#', $csp);
        $this->assertDoesNotMatchRegularExpression('#(script|connect)-src [^;]*facebook#', $csp);

        $tracked = $this->store('matcha');
        StoreOption::updateStoreOption($tracked->id, 'integration_ga_id', 'G-TEST12345');
        StoreOption::updateStoreOption($tracked->id, 'integration_meta_pixel_id', '1234567890');
        $csp = $header($this->get('/'.$tracked->slug));
        $this->assertMatchesRegularExpression('#script-src [^;]*https://www\.googletagmanager\.com#', $csp);
        $this->assertMatchesRegularExpression('#connect-src [^;]*https://\*\.analytics\.google\.com#', $csp);
        $this->assertMatchesRegularExpression('#script-src [^;]*https://connect\.facebook\.net#', $csp);
    }
}
