<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Messaging;

use Juwri\Modules\Messaging\Models\PushSubscription;
use Juwri\Modules\Messaging\Services\StaffPush\VapidWebPushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Minishlink\WebPush\VAPID;
use Tests\TestCase;

/**
 * PARITY-RUN follow-up #4 — prove the keys are actually CONSUMED.
 *
 * MobilePushBindingTest proves a real sender is bound and that an unconfigured
 * install no-ops. What it does not prove — because it swaps in a spy that
 * overrides send() — is that the real VapidWebPushSender, given real keys, gets
 * PAST its "no keys configured" guard and into the minishlink send path. Without
 * that, "we generated VAPID keys" and "web push actually leaves the building"
 * are two different claims.
 *
 * The tell: on a web subscription with an unusable crypto key, the real path
 * throws inside minishlink's encryption (the keys are used there, before any
 * network I/O) and the sender logs `[push] web-push send errored`. With NO VAPID
 * keys it returns before that log. So the log firing is proof the keys were
 * consumed — and it is deterministic, because encryption fails offline.
 */
class VapidSenderConsumesKeysTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStores(4242); // P1-04 5b: fixture store ids must be real stores
    }

    private function webSub(): PushSubscription
    {
        // A well-formed-looking but cryptographically unusable p256dh, so the
        // minishlink encryption step (which USES the VAPID + subscription keys)
        // fails locally instead of the code reaching the network.
        return PushSubscription::store([
            'endpoint' => 'https://push.example.test/'.Str::random(20),
            'keys' => ['p256dh' => base64_encode(str_repeat('x', 65)), 'auth' => base64_encode(Str::random(16))],
            'contentEncoding' => 'aesgcm',
        ], 4242);
    }

    public function test_with_real_keys_the_sender_enters_the_send_path(): void
    {
        $keys = VAPID::createVapidKeys();
        config([
            'webpush.public_key' => $keys['publicKey'],
            'webpush.private_key' => $keys['privateKey'],
            'webpush.subject' => 'mailto:test@example.com',
        ]);

        Log::spy();

        // Returns true (never prunes on a transient/crypto failure), but the log
        // proves it went past the config guard into the real minishlink path.
        $this->assertTrue(new VapidWebPushSender()->send($this->webSub(), ['title' => 'hi', 'body' => 'there']));

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($msg) => str_contains((string) $msg, 'web-push send errored'))
            ->once();
    }

    public function test_with_no_keys_the_sender_short_circuits_before_the_send_path(): void
    {
        config(['webpush.public_key' => '', 'webpush.private_key' => '']);

        Log::spy();

        $this->assertTrue(new VapidWebPushSender()->send($this->webSub(), ['title' => 'hi']));

        // No attempt was made — so no "errored" log. This is the exact state the
        // whole follow-up exists to leave behind: the difference between keys
        // present and absent is observable.
        Log::shouldNotHaveReceived('info');
    }

    public function test_the_generated_public_key_is_a_valid_application_server_key(): void
    {
        // The browser decodes the public key as a base64url P-256 point: 65 bytes
        // (0x04 || X || Y). If the generator ever produced a wrong-length key,
        // every subscribe() call would throw in the browser.
        $keys = VAPID::createVapidKeys();
        $decoded = $this->b64urlDecode($keys['publicKey']);

        $this->assertSame(65, strlen($decoded), 'applicationServerKey must be a 65-byte uncompressed P-256 point');
        $this->assertSame("\x04", $decoded[0], 'an uncompressed EC point starts with 0x04');
    }

    private function b64urlDecode(string $s): string
    {
        return base64_decode(strtr($s, '-_', '+/').str_repeat('=', (4 - strlen($s) % 4) % 4));
    }
}
