<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Controllers\User\StoreController;
use App\Http\Middleware\Installed;
use App\Support\Csp\StorefrontPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Models\StoreDomain;
use Juwri\Modules\Storefront\Services\StoreAddressResolver;
use Tests\TestCase;

/**
 * P1-20 slice 4 (J28): the storefront CSP is ENFORCED (nonce-based, not report-only) on a store's own origin
 * — verified custom domains, platform subdomains and, in split mode, the storefront host.
 */
class StorefrontCspTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
        config(['csp.enabled' => true, 'csp.enforce' => false, 'csp.policy' => '', 'csp.report_only_policy' => StorefrontPolicy::class]);
        $this->app->instance(StoreController::class, new class extends StoreController
        {
            public function publicView($slug)
            {
                return response('<script nonce="'.csp_nonce().'">ok()</script>');
            }
        });
        $user = User::factory()->create(['status' => 1]);
        $this->store = Store::create([
            'user_id' => $user->id, 'slug' => 'csp-cafe', 'color' => '#111', 'title' => 'R', 'sub_title' => 'x',
            'description' => 'x', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1', 'phone' => '1',
        ]);
        StoreDomain::create(['store_id' => $this->store->id, 'type' => 'custom', 'host' => 'menu.csp.test', 'verified_at' => now(), 'verification_token' => 't']);
    }

    private function assertEnforcedWithPageNonce(TestResponse $res): void
    {
        $res->assertOk();
        $header = (string) $res->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("script-src 'self' 'nonce-", $header);
        $this->assertStringContainsString("object-src 'none'", $header);
        $this->assertFalse($res->headers->has('Content-Security-Policy-Report-Only'), 'enforced, not report-only');
        preg_match("/'nonce-([^']+)'/", $header, $m);
        $this->assertNotEmpty($m[1] ?? null);
        $this->assertStringContainsString('nonce="'.$m[1].'"', (string) $res->getContent(), 'the page and the header share the nonce');
    }

    public function test_custom_domain_storefront_gets_an_enforced_nonce_csp(): void
    {
        $this->assertEnforcedWithPageNonce($this->get('https://menu.csp.test/'));
    }

    public function test_split_mode_storefront_host_gets_an_enforced_nonce_csp(): void
    {
        StoreDomain::query()->update(['is_primary' => false]);
        app(StoreAddressResolver::class)->flush();
        config(['hosts.mode' => 'split', 'hosts.app_url' => 'https://app.juwri.test', 'hosts.admin_url' => 'https://admin.juwri.test',
            'hosts.storefront_url' => 'https://juwri-store.test', 'hosts.marketing_url' => 'https://juwri.test']);

        $this->assertEnforcedWithPageNonce($this->get('https://juwri-store.test/csp-cafe'));
    }

    public function test_shared_platform_host_keeps_the_configured_mode_and_off_restores_it_everywhere(): void
    {
        StoreDomain::query()->update(['is_primary' => false]);
        app(StoreAddressResolver::class)->flush();
        // P4-17 follow-up: enforced everywhere by default; under the old store_hosts mode the shared host stays report-only
        config(['csp.storefront_enforce' => 'all']); // the shipped default (a slot's .env may still carry store_hosts)
        $this->assertTrue($this->get('https://localhost/csp-cafe')->assertOk()->headers->has('Content-Security-Policy'), 'default: enforced on every storefront page');
        config(['csp.enforce' => false, 'csp.policy' => '', 'csp.report_only_policy' => StorefrontPolicy::class, 'csp.storefront_enforce' => 'store_hosts']);
        $res = $this->get('https://localhost/csp-cafe')->assertOk();
        $this->assertTrue($res->headers->has('Content-Security-Policy-Report-Only'), 'store_hosts mode, path form: report-only unless CSP_ENFORCE');

        config(['csp.storefront_enforce' => 'off']);
        StoreDomain::query()->update(['is_primary' => true]);
        app(StoreAddressResolver::class)->flush();
        $res = $this->get('https://menu.csp.test/')->assertOk();
        $this->assertFalse($res->headers->has('Content-Security-Policy'));
        $this->assertTrue($res->headers->has('Content-Security-Policy-Report-Only'));

        config(['csp.storefront_enforce' => 'all']);
        StoreDomain::query()->update(['is_primary' => false]);
        app(StoreAddressResolver::class)->flush();
        $this->assertEnforcedWithPageNonce($this->get('https://localhost/csp-cafe'));
    }
}
