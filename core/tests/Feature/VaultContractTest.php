<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Ai\Services\AiProviderFactory;
use Juwri\Modules\Messaging\Services\SmsService;
use Tests\TestCase;

/**
 * Locks the contract between the Integrations vault (per-provider Credential
 * rows with neutral secret key names) and the services that consume them.
 */
class VaultContractTest extends TestCase
{
    use RefreshDatabase;

    private function storeFixture(): Store
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $user->id, 'slug' => Str::slug('r'.Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_ai_byok_row_overrides_provider_key_and_model(): void
    {
        $store = $this->storeFixture();
        // Platform key empty; the restaurant supplies its own via the vault.
        config(['ai.providers.mimo' => [
            'driver' => 'openai', 'base_url' => 'https://api.xiaomimimo.com/v1',
            'api_key' => '', 'model' => 'platform-model', 'supports_json' => true, 'supports_vision' => false,
        ]]);

        Credential::create([
            'store_id' => $store->id, 'feature' => 'ai', 'provider' => 'mimo', 'active' => true,
            'secrets' => ['api_key' => 'byok-key-123', 'model' => 'byok-model'],
        ]);

        $provider = app(AiProviderFactory::class)->make('mimo', $store->id);
        $this->assertNotNull($provider);
        $this->assertTrue($provider->isConfigured()); // configured via BYOK despite empty platform key

        Http::fake(['api.xiaomimimo.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'ok']]], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ], 200)]);

        $provider->chat([['role' => 'user', 'content' => 'hi']]);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer byok-key-123')
                && ($request->data()['model'] ?? null) === 'byok-model';
        });
    }

    public function test_sms_vault_twilio_secret_names_are_mapped(): void
    {
        $store = $this->storeFixture();

        Credential::create([
            'store_id' => $store->id, 'feature' => 'sms', 'provider' => 'twilio', 'active' => true,
            'secrets' => ['sid' => 'AC_test_sid', 'auth_token' => 'tok_test', 'from_number' => '+15550000000'],
        ]);

        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);

        $ok = app(SmsService::class)->send('+16505551234', 'Hello', $store->id, 'order');

        $this->assertTrue($ok);
        Http::assertSent(function ($request) {
            // vault 'sid' -> Twilio basic-auth username; 'from_number' -> From
            return str_contains($request->url(), 'AC_test_sid/Messages.json')
                && ($request->data()['From'] ?? null) === '+15550000000';
        });
        $this->assertDatabaseHas('sms_logs', ['provider' => 'twilio', 'status' => 'sent']);
    }
}
