<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Agents\Models\PhoneLine;
use Juwri\Modules\Agents\Services\Telephony\CallbackConfigurator;
use Tests\Support\TestPlans;
use Tests\TestCase;

class CallbackConfiguratorTest extends TestCase
{
    use RefreshDatabase;

    private function line(string $provider, array $secrets): PhoneLine
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        Credential::create(['store_id' => $r->id, 'feature' => $provider, 'provider' => $provider, 'secrets' => $secrets, 'active' => true]);

        return PhoneLine::create(['store_id' => $r->id, 'provider' => $provider, 'number' => '+1999'.rand(1000, 9999), 'active' => true]);
    }

    public function test_twilio_sets_status_callback_on_the_number(): void
    {
        Http::fake([
            'api.twilio.com/*IncomingPhoneNumbers.json*' => Http::response(['incoming_phone_numbers' => [['sid' => 'PN123']]]),
            'api.twilio.com/*PN123.json' => Http::response(['sid' => 'PN123']),
        ]);
        $line = $this->line('twilio', ['account_sid' => 'AC1', 'auth_token' => 'tok-secret']);

        $this->assertTrue(app(CallbackConfigurator::class)->configure($line));
        // Idempotent: a reconnect re-applies the same value.
        $this->assertTrue(app(CallbackConfigurator::class)->configure($line));

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/Accounts/AC1/IncomingPhoneNumbers/PN123.json')
            && $r['StatusCallback'] === url('/voice/twilio/status') && $r['StatusCallbackMethod'] === 'POST');
    }

    public function test_telnyx_patches_texml_application_status_callback(): void
    {
        Http::fake(['api.telnyx.com/*' => Http::response(['data' => []])]);
        $line = $this->line('telnyx', ['api_key' => 'KEY-secret', 'connection_id' => '777']);

        $this->assertTrue(app(CallbackConfigurator::class)->configure($line));

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->url() === 'https://api.telnyx.com/v2/texml_applications/777'
            && $r['status_callback'] === url('/voice/telnyx/status') && $r['status_callback_method'] === 'POST'
            && $r->hasHeader('Authorization', 'Bearer KEY-secret'));
    }

    public function test_missing_credentials_skip_without_http(): void
    {
        Http::fake();
        $this->assertFalse(app(CallbackConfigurator::class)->configure($this->line('telnyx', ['public_key' => 'x'])));
        Http::assertNothingSent();
    }

    public function test_provider_failure_is_swallowed_and_secrets_not_logged(): void
    {
        Http::fake(['api.telnyx.com/*' => fn () => throw new \RuntimeException('boom KEY-secret')]);
        Log::spy();
        $line = $this->line('telnyx', ['api_key' => 'KEY-secret', 'connection_id' => '777']);

        $this->assertFalse(app(CallbackConfigurator::class)->configure($line));
        Log::shouldHaveReceived('warning')->withArgs(fn ($m, $ctx) => ! str_contains(json_encode($ctx), 'KEY-secret'))->once();
    }
}
