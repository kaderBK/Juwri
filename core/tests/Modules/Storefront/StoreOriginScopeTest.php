<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Models\StoreDomain;
use Juwri\Modules\Storefront\Services\StoreDomains;
use Tests\TestCase;

/**
 * P1-20 slice 5 (J28): per-address PWA scope (manifest + service worker) and the Caddy on-demand TLS `ask`
 * endpoint that only approves hosts the platform serves.
 */
class StoreOriginScopeTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
        $user = User::factory()->create(['status' => 1]);
        $this->store = Store::create([
            'user_id' => $user->id, 'slug' => 'pwa-pantry', 'color' => '#111', 'title' => 'Pantry', 'sub_title' => 'x',
            'description' => 'x', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1', 'phone' => '1',
        ]);
    }

    public function test_pwa_manifest_is_scoped_to_the_store_address(): void
    {
        $this->get('https://localhost/pwa-pantry/manifest.json')->assertOk()
            ->assertJsonPath('scope', '/pwa-pantry')->assertJsonPath('start_url', '/pwa-pantry')->assertJsonPath('id', '/pwa-pantry');

        StoreDomain::create(['store_id' => $this->store->id, 'type' => 'custom', 'host' => 'menu.pwa.test', 'verified_at' => now(), 'verification_token' => 't']);
        $this->get('https://menu.pwa.test/manifest.json')->assertOk()->assertJsonPath('scope', '/')->assertJsonPath('start_url', '/');
        $this->get('https://menu.pwa.test/pwa-pantry/manifest.json')->assertOk()->assertJsonPath('scope', '/');
    }

    public function test_service_worker_never_caches_private_pages_or_other_scopes(): void
    {
        $sw = (string) file_get_contents(dirname(base_path()).'/sw-menu.js');
        $this->assertStringContainsString('request.url.indexOf(self.registration.scope) !== 0', $sw);
        $this->assertStringContainsString("cc.indexOf('private') === -1", $sw);
        $this->assertStringContainsString('new URL(self.registration.scope).pathname', $sw, 'cache name is per scope');

        $layout = (string) file_get_contents(resource_path('views/store_templates/layout.blade.php'));
        $this->assertStringContainsString('{ scope: mbSwScope }', $layout);
    }

    public function test_tls_ask_approves_only_served_hosts(): void
    {
        StoreDomain::create(['store_id' => $this->store->id, 'type' => 'custom', 'host' => 'menu.tls.test', 'verified_at' => now(), 'verification_token' => 't']);
        StoreDomain::create(['store_id' => $this->store->id, 'type' => 'custom', 'host' => 'pending.tls.test', 'verified_at' => null, 'verification_token' => 't']);
        StoreDomains::claimSubdomain($this->store, 'pantry');

        foreach (['menu.tls.test', 'www.menu.tls.test', 'pantry.localhost', 'localhost'] as $ok) {
            $this->get('/api/tls/ask?domain='.$ok)->assertOk();
        }
        foreach (['pending.tls.test', 'www.pending.tls.test', 'nobody.localhost', 'evil.test', ''] as $refused) {
            $this->get('/api/tls/ask?domain='.$refused)->assertNotFound();
        }

        config(['hosts.tls_ask_token' => 's3cret']);
        $this->get('/api/tls/ask?domain=menu.tls.test')->assertNotFound();
        $this->get('/api/tls/ask?domain=menu.tls.test&token=s3cret')->assertOk();
    }
}
