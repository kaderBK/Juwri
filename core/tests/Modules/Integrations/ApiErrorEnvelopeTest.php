<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Integrations\Models\ApiToken;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * A5-4: every error on an api/v1 route resolves to the ONE envelope
 * { message, code, errors? } — auth failures, validation, and not-found alike.
 */
class ApiErrorEnvelopeTest extends TestCase
{
    use RefreshDatabase;

    private function storeFixture(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]),
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function tokenFor(Store $r, string $ability): string
    {
        $raw = Str::random(48);
        ApiToken::create([
            'user_id' => $r->user_id, 'name' => 'T', 'token' => hash('sha256', $raw),
            'abilities' => [$ability], 'last_used_at' => null,
        ]);

        return $raw;
    }

    public function test_missing_token_is_enveloped_401(): void
    {
        $this->getJson('/api/v1/stores')
            ->assertStatus(401)
            ->assertJsonStructure(['message', 'code'])
            ->assertJsonPath('code', 'unauthenticated');
    }

    public function test_bad_token_is_enveloped_401(): void
    {
        $this->getJson('/api/v1/stores', ['Authorization' => 'Bearer nope'])
            ->assertStatus(401)
            ->assertJsonPath('code', 'unauthenticated');
    }

    public function test_missing_ability_is_enveloped_403(): void
    {
        $r = $this->storeFixture();
        $raw = $this->tokenFor($r, 'read'); // read token on a menu:write route
        $this->postJson('/api/v1/stores/'.$r->id.'/menu/items', ['name' => 'X'], ['Authorization' => 'Bearer '.$raw])
            ->assertStatus(403)
            ->assertJsonStructure(['message', 'code'])
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_validation_error_is_enveloped_422_with_errors(): void
    {
        $r = $this->storeFixture();
        $raw = $this->tokenFor($r, 'write');
        // Empty body → framework ValidationException on the menu-item rules.
        $this->postJson('/api/v1/stores/'.$r->id.'/menu/items', [], ['Authorization' => 'Bearer '.$raw])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'code', 'errors'])
            ->assertJsonPath('code', 'validation_failed');
    }

    public function test_not_found_is_enveloped_404(): void
    {
        $r = $this->storeFixture();
        $raw = $this->tokenFor($r, 'read');
        // A restaurant id the token does not own → owner-scope 404 (never 403).
        $this->getJson('/api/v1/stores/99999999/menu', ['Authorization' => 'Bearer '.$raw])
            ->assertStatus(404)
            ->assertJsonStructure(['message', 'code']);
    }
}
