<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use Juwri\Modules\Integrations\Models\ApiToken;
use Juwri\Core\Models\User;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * A5-6: an owner token can create + list + delete a webhook endpoint over the API
 * (the resource Zapier/Make REST-Hooks subscribe against).
 */
class WebhookEndpointApiTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P ' . Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => json_encode(['restaurant_limit' => 999]), 'status' => 1,
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function token(User $u, string $ability): string
    {
        $raw = Str::random(48);
        ApiToken::create(['user_id' => $u->id, 'name' => 'T', 'token' => hash('sha256', $raw), 'abilities' => [$ability], 'last_used_at' => null]);

        return $raw;
    }

    public function test_owner_token_crud_round_trips(): void
    {
        $owner = $this->owner();
        $write = $this->token($owner, 'write');

        // create
        $res = $this->postJson('/api/v1/webhook-endpoints', [
            'url' => 'https://93.184.216.34/in',
            'events' => ['order.created', 'order.paid'],
        ], ['Authorization' => 'Bearer ' . $write])->assertStatus(201)
            ->assertJsonStructure(['id', 'url', 'events', 'active', 'secret']);
        $id = $res->json('id');

        // list
        $this->getJson('/api/v1/webhook-endpoints', ['Authorization' => 'Bearer ' . $write])
            ->assertOk()->assertJsonPath('data.0.id', $id)
            ->assertJsonMissingPath('data.0.secret'); // secret only on create

        // delete
        $this->deleteJson('/api/v1/webhook-endpoints/' . $id, [], ['Authorization' => 'Bearer ' . $write])
            ->assertStatus(204);
        $this->assertDatabaseMissing('webhook_endpoints', ['id' => $id]);
    }

    public function test_invalid_event_and_ssrf_url_are_rejected(): void
    {
        $owner = $this->owner();
        $write = $this->token($owner, 'write');

        $this->postJson('/api/v1/webhook-endpoints', [
            'url' => 'https://93.184.216.34/in', 'events' => ['not.a.real.event'],
        ], ['Authorization' => 'Bearer ' . $write])->assertStatus(422)->assertJsonPath('code', 'validation_failed');

        $this->postJson('/api/v1/webhook-endpoints', [
            'url' => 'http://169.254.169.254/latest/meta-data', 'events' => ['order.created'],
        ], ['Authorization' => 'Bearer ' . $write])->assertStatus(422);
    }

    public function test_read_token_cannot_write(): void
    {
        $owner = $this->owner();
        $read = $this->token($owner, 'read');
        $this->postJson('/api/v1/webhook-endpoints', [
            'url' => 'https://93.184.216.34/in', 'events' => ['order.created'],
        ], ['Authorization' => 'Bearer ' . $read])->assertStatus(403)->assertJsonPath('code', 'forbidden');
    }

    /**
     * F0-1: the granular `webhooks` resource is now mintable. A `webhooks:write`
     * token — the scope a Zapier/Make/n8n connection asks for — can subscribe,
     * while a token scoped to a *different* resource (`orders:write`) cannot.
     */
    public function test_granular_webhooks_token_can_subscribe_but_other_resources_cannot(): void
    {
        $owner = $this->owner();

        $webhooks = $this->token($owner, 'webhooks:write');
        $this->postJson('/api/v1/webhook-endpoints', [
            'url' => 'https://93.184.216.34/in', 'events' => ['order.created'],
        ], ['Authorization' => 'Bearer ' . $webhooks])->assertStatus(201)
            ->assertJsonStructure(['id', 'url', 'events', 'active', 'secret']);

        // A token holding only orders:write must NOT reach the webhook resource.
        $orders = $this->token($owner, 'orders:write');
        $this->postJson('/api/v1/webhook-endpoints', [
            'url' => 'https://93.184.216.34/in', 'events' => ['order.created'],
        ], ['Authorization' => 'Bearer ' . $orders])->assertStatus(403)->assertJsonPath('code', 'forbidden');

        // A webhooks:read token can list but not create.
        $read = $this->token($owner, 'webhooks:read');
        $this->getJson('/api/v1/webhook-endpoints', ['Authorization' => 'Bearer ' . $read])->assertOk();
        $this->postJson('/api/v1/webhook-endpoints', [
            'url' => 'https://93.184.216.34/in', 'events' => ['order.created'],
        ], ['Authorization' => 'Bearer ' . $read])->assertStatus(403);
    }

    /**
     * F0-1 settled decision: legacy coarse `read`/`write` tokens keep covering
     * `webhooks:*` through the `*` resource rule (ApiToken::abilityCovers) — this is
     * documented, accepted back-compat, not tightened. Pin it so nobody "fixes" it.
     */
    public function test_webhooks_resource_is_selectable_and_covered_by_coarse_tokens(): void
    {
        $this->assertContains('webhooks:read', ApiToken::selectableAbilities());
        $this->assertContains('webhooks:write', ApiToken::selectableAbilities());

        $this->assertTrue(ApiToken::abilityCovers('write', 'webhooks:write'));
        $this->assertTrue(ApiToken::abilityCovers('read', 'webhooks:read'));
        $this->assertTrue(ApiToken::abilityCovers('write', 'webhooks:read')); // write ⊃ read
        $this->assertFalse(ApiToken::abilityCovers('read', 'webhooks:write'));
        $this->assertFalse(ApiToken::abilityCovers('orders:write', 'webhooks:write'));
    }

    /**
     * B1-b: a REST-Hook unsubscribe is idempotent — deleting an endpoint twice (a
     * Zap toggled off, or a retried teardown) is terminal-safe, both 204.
     */
    public function test_delete_is_idempotent(): void
    {
        $owner = $this->owner();
        $write = $this->token($owner, 'webhooks:write');

        $id = $this->postJson('/api/v1/webhook-endpoints', [
            'url' => 'https://93.184.216.34/in', 'events' => ['order.created'],
        ], ['Authorization' => 'Bearer ' . $write])->assertStatus(201)->json('id');

        $this->deleteJson('/api/v1/webhook-endpoints/' . $id, [], ['Authorization' => 'Bearer ' . $write])->assertStatus(204);
        // Second delete of the now-gone endpoint is still 204, not 404.
        $this->deleteJson('/api/v1/webhook-endpoints/' . $id, [], ['Authorization' => 'Bearer ' . $write])->assertStatus(204);
    }

    /** B1-b: list + delete are scoped to the token owner — no cross-tenant leak. */
    public function test_endpoints_are_scoped_to_the_token_owner(): void
    {
        $a = $this->owner();
        $b = $this->owner();
        $tokenA = $this->token($a, 'write');
        $tokenB = $this->token($b, 'write');

        $bId = $this->postJson('/api/v1/webhook-endpoints', [
            'url' => 'https://93.184.216.34/b', 'events' => ['order.created'],
        ], ['Authorization' => 'Bearer ' . $tokenB])->assertStatus(201)->json('id');

        // Owner A's list is empty and never shows B's endpoint.
        $this->getJson('/api/v1/webhook-endpoints', ['Authorization' => 'Bearer ' . $tokenA])
            ->assertOk()->assertJsonCount(0, 'data');

        // A deleting B's id is a no-op 204 (owner-scoped find never sees it), and B's row survives.
        $this->deleteJson('/api/v1/webhook-endpoints/' . $bId, [], ['Authorization' => 'Bearer ' . $tokenA])->assertStatus(204);
        $this->assertDatabaseHas('webhook_endpoints', ['id' => $bId]);
    }
}
