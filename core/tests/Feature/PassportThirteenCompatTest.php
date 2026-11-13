<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Juwri\Modules\Auth\Models\OAuthClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Once;
use Laravel\Passport\Bridge\ClientRepository;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * P1-00a: Passport 12 -> 13 behaviour juwri relies on (see Juwri\Modules\Auth\Models\OAuthClient).
 */
class PassportThirteenCompatTest extends TestCase
{
    use RefreshDatabase;

    private function client(array $over = []): OAuthClient
    {
        return OAuthClient::create($over + [
            'name' => 'C', 'secret' => 'plain-secret-plain-secret-plain-secret-0', 'provider' => 'users',
            'redirect' => 'https://c.test/cb', 'personal_access_client' => false, 'password_client' => false, 'revoked' => false,
        ]);
    }

    public function test_passport_uses_the_juwri_client_model_with_integer_ids(): void
    {
        $this->assertSame(OAuthClient::class, Passport::clientModel());
        $this->assertFalse(Passport::$clientUuids);
        $this->assertIsInt($this->client()->getKey());
    }

    public function test_the_secret_is_stored_as_given_for_session_token_signing(): void
    {
        $client = $this->client();

        $this->assertSame('plain-secret-plain-secret-plain-secret-0', $client->fresh()->secret);
        $this->assertSame('plain-secret-plain-secret-plain-secret-0', $client->plain_secret);
    }

    public function test_plain_and_hashed_secrets_both_validate(): void
    {
        $plain = $this->client();
        $hashed = $this->client(['secret' => 'x']);
        $hashed->forceFill(['secret' => Hash::make('hashed-secret')])->save();
        Once::flush();

        $repo = app(ClientRepository::class);
        $this->assertTrue($repo->validateClient((string) $plain->id, 'plain-secret-plain-secret-plain-secret-0', 'authorization_code'));
        $this->assertFalse($repo->validateClient((string) $plain->id, 'nope', 'authorization_code'));
        $this->assertTrue($repo->validateClient((string) $hashed->id, 'hashed-secret', 'authorization_code'));
        $this->assertFalse($repo->validateClient((string) $hashed->id, '', 'authorization_code'));
    }

    public function test_partner_clients_only_get_authorization_code_and_refresh_grants(): void
    {
        $client = $this->client();

        $this->assertSame(['authorization_code', 'refresh_token'], $client->grant_types);
        $this->assertFalse($client->hasGrantType('client_credentials'),
            'client_credentials would make TokenGuard treat user #N as a machine token for client #N');
        $this->assertFalse(app(ClientRepository::class)->validateClient((string) $client->id, 'plain-secret-plain-secret-plain-secret-0', 'client_credentials'));
    }
}
