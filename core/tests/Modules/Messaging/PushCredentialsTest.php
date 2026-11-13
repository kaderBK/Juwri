<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Messaging;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Messaging\Services\MobilePush\PushCredentials;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * PARITY-RUN T9 — BYOK resolution order for Firebase credentials, and the
 * promise that a secret never travels back out to a caller that renders it.
 */
class PushCredentialsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.fcm.server_key' => '', 'services.fcm.project_id' => '']);
    }

    /** `credentials.store_id` is a real FK, so a store has to exist. */
    private function store(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1,
            'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** A syntactically valid (throwaway) service-account blob. */
    private function serviceAccountJson(string $project = 'demo-project'): string
    {
        return json_encode([
            'type' => 'service_account',
            'project_id' => $project,
            'client_email' => 'fcm@'.$project.'.iam.gserviceaccount.com',
            'private_key' => "-----BEGIN PRIVATE KEY-----\nAAAA\n-----END PRIVATE KEY-----\n",
        ]);
    }

    private function vault(?int $storeId, array $secrets): Credential
    {
        return Credential::create([
            'store_id' => $storeId,
            'feature' => PushCredentials::FEATURE,
            'provider' => 'firebase',
            'secrets' => $secrets,
            'active' => true,
        ]);
    }

    public function test_nothing_configured_resolves_to_none(): void
    {
        $store = $this->store();

        $this->assertSame(PushCredentials::MODE_NONE, PushCredentials::forStore((int) $store->id)['mode']);
        $this->assertFalse(PushCredentials::configured((int) $store->id));
    }

    public function test_env_server_key_is_the_last_resort(): void
    {
        config(['services.fcm.server_key' => 'legacy-key']);
        $store = $this->store();

        $creds = PushCredentials::forStore((int) $store->id);
        $this->assertSame(PushCredentials::MODE_LEGACY, $creds['mode']);
        $this->assertSame('legacy-key', $creds['server_key']);
    }

    public function test_platform_default_beats_env(): void
    {
        config(['services.fcm.server_key' => 'legacy-key']);
        $store = $this->store();
        $this->vault(null, PushCredentials::parseServiceAccount($this->serviceAccountJson('platform')));

        $creds = PushCredentials::forStore((int) $store->id);
        $this->assertSame(PushCredentials::MODE_V1, $creds['mode']);
        $this->assertSame('platform', $creds['project_id']);
    }

    public function test_a_merchant_row_beats_the_platform_default_and_never_leaks(): void
    {
        $mine = $this->store();
        $theirs = $this->store();

        $this->vault(null, PushCredentials::parseServiceAccount($this->serviceAccountJson('platform')));
        $this->vault((int) $mine->id, PushCredentials::parseServiceAccount($this->serviceAccountJson('merchant')));

        $this->assertSame('merchant', PushCredentials::forStore((int) $mine->id)['project_id']);
        // The other store still resolves the platform default, never the merchant's.
        $this->assertSame('platform', PushCredentials::forStore((int) $theirs->id)['project_id']);
    }

    public function test_an_inactive_row_is_ignored(): void
    {
        $store = $this->store();
        $this->vault((int) $store->id, PushCredentials::parseServiceAccount($this->serviceAccountJson()))
            ->update(['active' => false]);

        $this->assertSame(PushCredentials::MODE_NONE, PushCredentials::forStore((int) $store->id)['mode']);
    }

    /** The projection the settings screen reads exposes no secret material. */
    public function test_status_never_exposes_a_secret(): void
    {
        $store = $this->store();
        $this->vault((int) $store->id, PushCredentials::parseServiceAccount($this->serviceAccountJson()));

        $status = PushCredentials::status((int) $store->id);

        $this->assertTrue($status['configured']);
        $this->assertTrue($status['own_credentials']);
        $this->assertSame('demo-project', $status['project_id']);
        $this->assertSame(['mode', 'configured', 'project_id', 'own_credentials'], array_keys($status));
        $this->assertStringNotContainsString('PRIVATE KEY', json_encode($status));
    }

    public function test_status_marks_a_platform_default_as_not_the_stores_own(): void
    {
        $store = $this->store();
        $this->vault(null, PushCredentials::parseServiceAccount($this->serviceAccountJson()));

        $status = PushCredentials::status((int) $store->id);
        $this->assertTrue($status['configured']);
        $this->assertFalse($status['own_credentials']);
    }

    public function test_parse_rejects_anything_that_is_not_a_service_account(): void
    {
        $this->assertNull(PushCredentials::parseServiceAccount('not json'));
        $this->assertNull(PushCredentials::parseServiceAccount('{}'));
        $this->assertNull(PushCredentials::parseServiceAccount(json_encode(['project_id' => 'p'])));
        // Right keys, no PEM body → not a usable key.
        $this->assertNull(PushCredentials::parseServiceAccount(json_encode([
            'project_id' => 'p', 'client_email' => 'e@x', 'private_key' => 'nope',
        ])));
    }

    /** Pasted JSON often arrives with the PEM newlines escaped; openssl needs them back. */
    public function test_parse_restores_escaped_newlines_in_the_pem(): void
    {
        $raw = '{"project_id":"p","client_email":"e@x","private_key":"-----BEGIN PRIVATE KEY-----\\nAAAA\\n-----END PRIVATE KEY-----\\n"}';

        $parsed = PushCredentials::parseServiceAccount($raw);
        $this->assertNotNull($parsed);
        $this->assertStringContainsString("\n", $parsed['private_key']);
        $this->assertStringNotContainsString('\\n', $parsed['private_key']);
    }

    /** The vault column is `encrypted:array` — the stored bytes must not carry the key. */
    public function test_secrets_are_stored_encrypted(): void
    {
        $store = $this->store();
        $this->vault((int) $store->id, PushCredentials::parseServiceAccount($this->serviceAccountJson()));

        $raw = (string) DB::table('credentials')->where('feature', PushCredentials::FEATURE)->value('secrets');

        $this->assertNotSame('', $raw);
        $this->assertStringNotContainsString('PRIVATE KEY', $raw);
        $this->assertStringNotContainsString('demo-project', $raw);
    }

    /** Resolution must be pure config/DB — never an outbound call. */
    public function test_resolution_makes_no_http_call(): void
    {
        Http::fake();
        $store = $this->store();
        $this->vault((int) $store->id, PushCredentials::parseServiceAccount($this->serviceAccountJson()));

        PushCredentials::forStore((int) $store->id);
        PushCredentials::status((int) $store->id);

        Http::assertNothingSent();
    }
}
