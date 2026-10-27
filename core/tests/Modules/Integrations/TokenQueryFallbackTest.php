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
 * S9 — the ?…_token query fallback leaks tokens into logs/Referer. It stays on
 * by default (accept + deprecation-log) and can be turned off with
 * config('auth.token_query_fallback')=false → header-only.
 */
class TokenQueryFallbackTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:User,1:string} */
    private function apiToken(): array
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $raw = Str::random(40);
        ApiToken::create([
            'user_id' => $owner->id, 'name' => 'T',
            'token' => hash('sha256', $raw), 'abilities' => ['restaurants:read'],
        ]);

        return [$owner, $raw];
    }

    public function test_query_fallback_on_authenticates_and_logs(): void
    {
        config(['auth.token_query_fallback' => true]);
        [, $raw] = $this->apiToken();
        Log::spy();

        $this->getJson(route('api.v1.stores.index').'?api_token='.$raw)->assertOk();

        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => $m === 'deprecated ?api_token used')->atLeast()->once();
    }

    public function test_query_fallback_off_rejects_query_but_accepts_header(): void
    {
        config(['auth.token_query_fallback' => false]);
        [, $raw] = $this->apiToken();

        // Query token no longer accepted.
        $this->getJson(route('api.v1.stores.index').'?api_token='.$raw)
            ->assertUnauthorized();

        // Authorization: Bearer still works.
        $this->getJson(route('api.v1.stores.index'), ['Authorization' => 'Bearer '.$raw])
            ->assertOk();
    }
}
