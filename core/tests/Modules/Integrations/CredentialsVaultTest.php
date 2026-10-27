<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\TestCase;

class CredentialsVaultTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
    }

    public function test_resolves_platform_default_when_no_merchant_credential_exists()
    {
        Credential::create([
            'store_id' => null,
            'feature' => 'ai',
            'provider' => 'openai',
            'secrets' => ['api_key' => 'platform-secret-key'],
            'active' => true,
        ]);

        $resolved = Credential::for('ai');
        $this->assertNotNull($resolved);
        $this->assertEquals('platform-secret-key', $resolved['api_key']);

        $resolvedForMerchant = Credential::for('ai', 999);
        $this->assertNotNull($resolvedForMerchant);
        $this->assertEquals('platform-secret-key', $resolvedForMerchant['api_key']);
    }

    public function test_resolves_merchant_override_when_merchant_credential_exists()
    {
        $user = User::factory()->create();
        $store = Store::create([
            'id' => 123,
            'user_id' => $user->id,
            'title' => 'Test Cafe',
            'slug' => 'test-cafe',
        ]);

        Credential::create([
            'store_id' => null,
            'feature' => 'ai',
            'provider' => 'openai',
            'secrets' => ['api_key' => 'platform-key'],
            'active' => true,
        ]);

        Credential::create([
            'store_id' => $store->id,
            'feature' => 'ai',
            'provider' => 'openai',
            'secrets' => ['api_key' => 'merchant-override-key'],
            'active' => true,
        ]);

        $resolved = Credential::for('ai', $store->id);
        $this->assertNotNull($resolved);
        $this->assertEquals('merchant-override-key', $resolved['api_key']);
    }

    public function test_inactive_credentials_are_ignored()
    {
        $user = User::factory()->create();
        $store = Store::create([
            'id' => 123,
            'user_id' => $user->id,
            'title' => 'Test Cafe',
            'slug' => 'test-cafe',
        ]);

        Credential::create([
            'store_id' => $store->id,
            'feature' => 'ai',
            'provider' => 'openai',
            'secrets' => ['api_key' => 'merchant-override-key'],
            'active' => false,
        ]);

        Credential::create([
            'store_id' => null,
            'feature' => 'ai',
            'provider' => 'openai',
            'secrets' => ['api_key' => 'platform-key'],
            'active' => true,
        ]);

        $resolved = Credential::for('ai', $store->id);
        $this->assertNotNull($resolved);
        $this->assertEquals('platform-key', $resolved['api_key']);
    }

    public function test_merchant_can_save_credentials()
    {
        $user = User::factory()->create();
        $store = Store::create([
            'id' => 123,
            'user_id' => $user->id,
            'title' => 'Test Cafe',
            'slug' => 'test-cafe',
        ]);

        $response = $this->actingAs($user)
            ->post(route('settings.integrations.update'), [
                'store_id' => $store->id,
                'feature' => 'ai',
                'provider' => 'openai',
                'secrets' => ['api_key' => 'my-new-key'],
                'active' => 1,
            ]);

        $response->assertStatus(302);

        $resolved = Credential::for('ai', $store->id);
        $this->assertEquals('my-new-key', $resolved['api_key']);
    }

    public function test_admin_can_save_platform_default_credentials()
    {
        $admin = User::factory()->create([
            'user_type' => 'admin',
        ]);

        $response = $this->actingAs($admin)
            ->post(route('admin.settings.integrations.update'), [
                'feature' => 'ai',
                'provider' => 'openai',
                'secrets' => ['api_key' => 'my-platform-key'],
                'active' => 1,
            ]);

        $response->assertStatus(302);

        $resolved = Credential::for('ai');
        $this->assertEquals('my-platform-key', $resolved['api_key']);
    }
}
