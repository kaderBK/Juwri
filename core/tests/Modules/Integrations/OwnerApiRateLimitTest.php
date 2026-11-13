<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\User;
use Juwri\Modules\Integrations\Models\ApiToken;
use Tests\TestCase;

/**
 * A1 — the owner token API is rate-limited PER TOKEN, not per IP. Two tokens from the
 * same IP each get their own budget; one token is capped regardless of source IP.
 */
class OwnerApiRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $owner): string
    {
        $raw = Str::random(40);
        ApiToken::create([
            'user_id' => $owner->id, 'name' => 't',
            'token' => hash('sha256', $raw),
            'abilities' => ['read', 'write', 'restaurants:read'],
        ]);

        return $raw;
    }

    public function test_two_tokens_same_ip_each_get_their_own_budget(): void
    {
        config(['api.owner_rate' => 2]);
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $a = $this->token($owner);
        $b = $this->token($owner);

        // Exhaust token A (2 allowed, 3rd is 429) — all from the same client IP.
        $this->getJson('/api/v1/stores', ['Authorization' => 'Bearer '.$a])->assertStatus(200);
        $this->getJson('/api/v1/stores', ['Authorization' => 'Bearer '.$a])->assertStatus(200);
        $this->getJson('/api/v1/stores', ['Authorization' => 'Bearer '.$a])->assertStatus(429);

        // Token B, same IP, must still have its full budget (not throttled by A).
        $this->getJson('/api/v1/stores', ['Authorization' => 'Bearer '.$b])->assertStatus(200);
    }

    public function test_one_token_two_ips_shares_one_budget(): void
    {
        config(['api.owner_rate' => 2]);
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $raw = $this->token($owner);
        $h = ['Authorization' => 'Bearer '.$raw];

        // The limiter keys on the token hash, so different source IPs share one bucket.
        $this->call('GET', '/api/v1/stores', [], [], [], ['REMOTE_ADDR' => '10.0.0.1'] + $this->transformHeadersToServerVars($h))->assertStatus(200);
        $this->call('GET', '/api/v1/stores', [], [], [], ['REMOTE_ADDR' => '10.0.0.2'] + $this->transformHeadersToServerVars($h))->assertStatus(200);
        $this->call('GET', '/api/v1/stores', [], [], [], ['REMOTE_ADDR' => '10.0.0.3'] + $this->transformHeadersToServerVars($h))->assertStatus(429);
    }

    public function test_config_overrides_the_ceiling(): void
    {
        config(['api.owner_rate' => 1]);
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $raw = $this->token($owner);
        $h = ['Authorization' => 'Bearer '.$raw];

        $this->getJson('/api/v1/stores', $h)->assertStatus(200);
        $this->getJson('/api/v1/stores', $h)->assertStatus(429);
    }
}
