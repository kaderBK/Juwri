<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Juwri\Core\Models\User;
use Juwri\Modules\Integrations\Models\ApiToken;
use Tests\TestCase;

/**
 * A5-1 — the `?api_token=` query path is deprecated (warned + sunset header) and the
 * `last_used_at` write is throttled to at most once per 60s per token.
 */
class ApiTokenDeprecationTest extends TestCase
{
    use RefreshDatabase;

    private function token(User $owner, $lastUsed = null): array
    {
        $raw = Str::random(40);
        $t = ApiToken::create([
            'user_id' => $owner->id, 'name' => 't',
            'token' => hash('sha256', $raw),
            'abilities' => ['read', 'restaurants:read'],
            'last_used_at' => $lastUsed,
        ]);

        return [$raw, $t];
    }

    public function test_query_token_works_but_is_deprecated(): void
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        [$raw] = $this->token($owner);

        Log::shouldReceive('warning')->atLeast()->once()
            ->withArgs(fn ($m, $c = []) => str_contains($m, 'deprecated ?api_token'));

        $this->getJson('/api/v1/stores?api_token='.$raw)
            ->assertOk()
            ->assertHeader('Deprecation', 'true')
            ->assertHeader('Sunset');
    }

    public function test_bearer_token_is_not_deprecated(): void
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        [$raw] = $this->token($owner);

        $res = $this->getJson('/api/v1/stores', ['Authorization' => 'Bearer '.$raw])->assertOk();
        $this->assertNull($res->headers->get('Deprecation'));
    }

    public function test_last_used_at_write_is_throttled(): void
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        // Fresh (recent) last_used_at → a call within 60s must NOT rewrite it.
        $stamp = now()->subSeconds(10);
        [$raw, $t] = $this->token($owner, $stamp);

        $this->getJson('/api/v1/stores', ['Authorization' => 'Bearer '.$raw])->assertOk();

        $t->refresh();
        $this->assertSame($stamp->toDateTimeString(), $t->last_used_at->toDateTimeString(), 'recent last_used_at must not be rewritten within 60s');
    }

    public function test_stale_last_used_at_is_refreshed(): void
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $stamp = now()->subMinutes(5);
        [$raw, $t] = $this->token($owner, $stamp);

        $this->getJson('/api/v1/stores', ['Authorization' => 'Bearer '.$raw])->assertOk();

        $t->refresh();
        $this->assertNotSame($stamp->toDateTimeString(), $t->last_used_at->toDateTimeString(), 'stale last_used_at must be refreshed');
    }
}
