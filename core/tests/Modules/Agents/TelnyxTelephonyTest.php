<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use Juwri\Core\Models\Credential;
use Juwri\Modules\Agents\Models\PhoneLine;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Agents\Services\Telephony\TelephonyProvider;
use Juwri\Modules\Agents\Services\Telephony\TelephonyRegistry;
use Juwri\Modules\Agents\Services\Telephony\TelnyxTelephonyProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

class TelnyxTelephonyTest extends TestCase
{
    use RefreshDatabase;

    private string $secretKey = '';

    private string $publicKey = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([\App\Http\Middleware\Installed::class, \App\Http\Middleware\PreventRequestForgery::class]);
        $pair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($pair);
        $this->publicKey = sodium_crypto_sign_publickey($pair);
    }

    private function line(): PhoneLine
    {
        $plan = TestPlans::create([
            'name' => 'P' . Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-' . Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        Credential::create([
            'store_id' => $r->id, 'feature' => 'telnyx', 'provider' => 'telnyx',
            'secrets' => ['public_key' => base64_encode($this->publicKey)], 'active' => true,
        ]);
        return PhoneLine::create([
            'store_id' => $r->id, 'provider' => 'telnyx', 'number' => '+1888' . rand(1000, 9999),
            'active' => true, 'greeting' => 'Telnyx hi', 'orders_enabled' => true,
        ]);
    }

    public function test_registry_resolves_telnyx_as_provider(): void
    {
        $this->assertInstanceOf(TelephonyProvider::class, TelephonyRegistry::get('telnyx'));
        $this->assertSame('telnyx', TelephonyRegistry::get('telnyx')->key());
    }

    public function test_telnyx_answers_with_texml_connect(): void
    {
        $line = $this->line();
        [$body] = new TelnyxTelephonyProvider()->answer($line, 'wss://x/y');
        $this->assertStringContainsString('<Connect>', $body);
        $this->assertStringContainsString('ConversationRelay', $body);
        $this->assertStringContainsString('Telnyx hi', $body);
    }

    /**
     * @param  array<string, string>  $headers
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function postSigned(PhoneLine $line, array $headers = [], ?int $timestamp = null, ?string $key = null): \Illuminate\Testing\TestResponse
    {
        $body = http_build_query(['To' => $line->number, 'From' => '+1', 'CallSid' => 'v3:t1']);
        $ts = (string) ($timestamp ?? time());
        $sig = base64_encode(sodium_crypto_sign_detached($ts.'|'.$body, $key ?? $this->secretKey));
        $server = ['CONTENT_TYPE' => 'application/x-www-form-urlencoded', 'HTTP_TELNYX_SIGNATURE_ED25519' => $sig, 'HTTP_TELNYX_TIMESTAMP' => $ts];
        foreach ($headers as $k => $v) {
            if ($v === '') {
                unset($server[$k]);
            } else {
                $server[$k] = $v;
            }
        }

        return $this->call('POST', route('voice.incoming', 'telnyx'), ['To' => $line->number, 'From' => '+1', 'CallSid' => 'v3:t1'], [], [], $server, $body);
    }

    public function test_signed_incoming_call_answers_via_shared_webhook(): void
    {
        $res = $this->postSigned($this->line());
        $res->assertOk();
        $this->assertStringContainsString('ConversationRelay', $res->getContent());
    }

    public function test_bad_ed25519_signature_rejected(): void
    {
        $this->postSigned($this->line(), [], null, sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()))->assertStatus(401);
    }

    public function test_stale_timestamp_rejected(): void
    {
        $this->postSigned($this->line(), [], time() - 301)->assertStatus(401);
    }

    public function test_missing_signature_or_timestamp_header_rejected(): void
    {
        $line = $this->line();
        $this->postSigned($line, ['HTTP_TELNYX_SIGNATURE_ED25519' => ''])->assertStatus(401);
        $this->postSigned($line, ['HTTP_TELNYX_TIMESTAMP' => ''])->assertStatus(401);
    }

    public function test_unsigned_telnyx_call_rejected(): void
    {
        $line = $this->line();
        $this->post(route('voice.incoming', 'telnyx'), ['To' => $line->number, 'From' => '+1'])->assertStatus(401);
    }
}
