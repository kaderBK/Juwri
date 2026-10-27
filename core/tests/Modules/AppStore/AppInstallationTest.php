<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\AppStore;

use App\Models\App;
use App\Models\AppInstallation;
use Juwri\Core\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\AppInstallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Juwri\Modules\Auth\Models\OAuthClient as Client;
use Laravel\Passport\Token;
use RuntimeException;
use Tests\TestCase;

/**
 * P3-2: the app install/uninstall lifecycle — approval gate, scope grant,
 * per-install webhook endpoint on the #99 rails, token revocation and the
 * app.uninstalled event on uninstall, reinstall reactivation.
 */
class AppInstallationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            \App\Http\Middleware\Installed::class,
            \App\Http\Middleware\PreventRequestForgery::class,
        ]);
    }

    private function makeApp(array $overrides = []): App
    {
        return App::create(array_merge([
            'name' => 'Sync App',
            'slug' => 'sync-app',
            'description' => 'Syncs things',
            'scopes' => ['menu:read', 'orders:read'],
            'webhook_url' => 'https://sync-app.test/webhooks',
            'webhook_secret' => 'whsec_test',
            'webhook_events' => ['order.paid'],
            'status' => 'approved',
        ], $overrides));
    }

    private function makeOwner(): User
    {
        return User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
    }

    public function test_unapproved_apps_cannot_be_installed(): void
    {
        $app = $this->makeApp(['status' => 'review']);

        $this->expectException(RuntimeException::class);
        new AppInstallService()->install($app, $this->makeOwner());
    }

    public function test_install_grants_scopes_and_wires_the_webhook_endpoint(): void
    {
        $app = $this->makeApp();
        $owner = $this->makeOwner();

        $installation = new AppInstallService()->install($app, $owner);

        $this->assertSame('active', $installation->status);
        $this->assertSame(['menu:read', 'orders:read'], $installation->granted_scopes);

        $endpoint = WebhookEndpoint::find($installation->webhook_endpoint_id);
        $this->assertSame('https://sync-app.test/webhooks', $endpoint->url);
        $this->assertSame('whsec_test', $endpoint->secret);
        $this->assertContains('order.paid', $endpoint->events);
        $this->assertContains('app.uninstalled', $endpoint->events, 'uninstall event always subscribed');
    }

    public function test_uninstall_revokes_tokens_fires_event_and_keeps_audit_row(): void
    {
        $client = Client::create([
            'name' => 'Sync App', 'secret' => 'secret', 'provider' => 'users',
            'redirect' => 'https://sync-app.test/cb',
            'personal_access_client' => false, 'password_client' => false, 'revoked' => false,
        ]);
        $app = $this->makeApp(['oauth_client_id' => $client->id]);
        $owner = $this->makeOwner();
        $service = new AppInstallService();
        $installation = $service->install($app, $owner);

        // The app holds a live token for this tenant.
        $token = Token::forceCreate([
            'id' => 'tok_'.bin2hex(random_bytes(20)),
            'user_id' => $owner->id,
            'client_id' => $client->id,
            'scopes' => ['menu:read'],
            'revoked' => false,
            'expires_at' => now()->addHours(2),
        ]);

        $service->uninstall($installation->fresh());

        $this->assertTrue($token->fresh()->revoked, 'OAuth token revoked on uninstall');

        $installation = $installation->fresh();
        $this->assertSame('uninstalled', $installation->status);
        $this->assertNotNull($installation->uninstalled_at);
        $this->assertDatabaseCount('app_installations', 1); // audit row kept

        // Endpoint no longer subscribed to anything (in-flight delivery-safe).
        $endpoint = WebhookEndpoint::find($installation->webhook_endpoint_id);
        $this->assertTrue((bool) $endpoint->active, 'stays active so the queued app.uninstalled delivery passes the handler check');
        $this->assertSame([], $endpoint->events);

        // The app.uninstalled delivery was recorded/queued for the endpoint.
        $this->assertDatabaseHas('webhook_deliveries', ['event' => 'app.uninstalled']);
    }

    public function test_reinstall_reactivates_the_same_row(): void
    {
        $app = $this->makeApp();
        $owner = $this->makeOwner();
        $service = new AppInstallService();

        $first = $service->install($app, $owner);
        $service->uninstall($first->fresh());
        $second = $service->install($app, $owner);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('active', $second->status);
        $this->assertNull($second->uninstalled_at);
        $this->assertDatabaseCount('app_installations', 1);
    }

    public function test_owner_routes_list_install_and_uninstall(): void
    {
        $app = $this->makeApp();
        $owner = $this->makeOwner();

        $this->actingAs($owner)
            ->post('apps/'.$app->id.'/install')
            ->assertOk()->assertJson(['ok' => true, 'status' => 'active']);

        $this->assertDatabaseHas('app_installations', ['app_id' => $app->id, 'user_id' => $owner->id, 'status' => 'active']);

        $this->actingAs($owner)
            ->post('apps/'.$app->id.'/uninstall')
            ->assertOk()->assertJson(['ok' => true, 'status' => 'uninstalled']);

        // Another owner cannot uninstall what they don't have.
        $this->actingAs($this->makeOwner())
            ->post('apps/'.$app->id.'/uninstall')
            ->assertNotFound();

        // Unapproved app → 404 from the install route's status gate.
        $draft = $this->makeApp(['slug' => 'draft-app', 'status' => 'draft']);
        $this->actingAs($owner)->post('apps/'.$draft->id.'/install')->assertNotFound();
    }
}
