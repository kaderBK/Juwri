<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Agents\Models\PhoneLine;
use Juwri\Modules\Agents\Services\Telephony\Bridge\BridgeToken;
use Juwri\Modules\Agents\Services\Telephony\Bridge\FakeVoiceBridge;
use Juwri\Modules\Agents\Services\Telephony\Bridge\VoiceBridge;
use Tests\Support\TestPlans;
use Tests\TestCase;

/** P6-09 V-01 + V-03: signed bridge token and the bridge route that requires it. */
class VoiceBridgeTokenTest extends TestCase
{
    use RefreshDatabase;

    private FakeVoiceBridge $bridge;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->bridge = new FakeVoiceBridge;
        $this->app->instance(VoiceBridge::class, $this->bridge);
    }

    private function line(string $number = '+1777', string $provider = 'fake'): PhoneLine
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

        return PhoneLine::create([
            'store_id' => $r->id, 'provider' => $provider, 'number' => $number,
            'active' => true, 'greeting' => 'Hi', 'orders_enabled' => true,
        ]);
    }

    private function bridgeUrl(PhoneLine $line, ?string $token, string $provider = 'fake'): string
    {
        return route('voice.bridge', [$provider, $line->id]).($token === null ? '' : '?t='.rawurlencode($token));
    }

    public function test_webhook_issues_token_that_opens_bridge_once(): void
    {
        $line = $this->line();
        $res = $this->post(route('voice.incoming', 'fake'), ['To' => '+1777', 'From' => '+1', 'CallSid' => 'CA-42'])->assertOk();
        $this->assertSame(1, preg_match('#/voice/fake/bridge/'.$line->id.'\?t=([^"]+)"#', $res->getContent(), $m));
        $token = rawurldecode($m[1]);

        $this->get($this->bridgeUrl($line, $token))->assertOk()->assertJson(['line' => $line->id, 'call' => 'CA-42']);
        $this->assertCount(1, $this->bridge->accepted);

        // Replay of the same token is refused.
        $this->get($this->bridgeUrl($line, $token))->assertStatus(401);
        $this->assertCount(1, $this->bridge->accepted);
    }

    public function test_webhook_without_call_id_is_refused(): void
    {
        $this->line();
        $this->post(route('voice.incoming', 'fake'), ['To' => '+1777', 'From' => '+1'])->assertStatus(400);
    }

    public function test_bridge_refuses_missing_garbage_and_tampered_tokens(): void
    {
        $line = $this->line();
        $this->get($this->bridgeUrl($line, null))->assertStatus(401);
        $this->get($this->bridgeUrl($line, 'v1.abc.def'))->assertStatus(401);

        $token = BridgeToken::issue('fake', $line, 'CA1');
        [$v, $body, $sig] = explode('.', $token);
        $claims = json_decode(base64_decode(strtr($body, '-_', '+/')), true);
        $claims['exp'] += 3600;
        $forged = $v.'.'.rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=').'.'.$sig;
        $this->get($this->bridgeUrl($line, $forged))->assertStatus(401);
        $this->assertSame([], $this->bridge->accepted);
    }

    public function test_bridge_refuses_token_for_other_line_store_or_provider(): void
    {
        $a = $this->line('+1777');
        $b = $this->line('+1888');

        // Token for line A presented on line B's URL.
        $this->get($this->bridgeUrl($b, BridgeToken::issue('fake', $a, 'CA1')))->assertStatus(401);

        // Line moved to another store after issue → store binding fails.
        $token = BridgeToken::issue('fake', $a, 'CA2');
        $a->forceFill(['store_id' => $b->store_id])->save();
        $this->get($this->bridgeUrl($a->fresh(), $token))->assertStatus(401);

        // Token issued for another provider.
        $this->assertNull(BridgeToken::verify(BridgeToken::issue('twilio', $b, 'CA3'), 'fake', $b));
        $this->get($this->bridgeUrl($b, BridgeToken::issue('twilio', $b, 'CA3')))->assertStatus(401);
        $this->assertSame([], $this->bridge->accepted);
    }

    public function test_bridge_refuses_expired_token(): void
    {
        $line = $this->line();
        $token = BridgeToken::issue('fake', $line, 'CA1');
        $this->travel(BridgeToken::TTL_SECONDS + 5)->seconds();
        $this->get($this->bridgeUrl($line, $token))->assertStatus(401);
        $this->assertSame([], $this->bridge->accepted);
    }

    public function test_token_signed_with_other_key_is_refused(): void
    {
        $line = $this->line();
        config(['services.telephony.bridge_secret' => 'other-secret']);
        $token = BridgeToken::issue('fake', $line, 'CA1');
        config(['services.telephony.bridge_secret' => 'real-secret']);
        $this->get($this->bridgeUrl($line, $token))->assertStatus(401);

        $this->get($this->bridgeUrl($line, BridgeToken::issue('fake', $line, 'CA1')))->assertOk();
    }

    public function test_inactive_line_and_unknown_provider_refused(): void
    {
        $line = $this->line();
        $token = BridgeToken::issue('fake', $line, 'CA1');
        $line->update(['active' => false]);
        $this->get($this->bridgeUrl($line, $token))->assertStatus(401);
        $this->get(route('voice.bridge', ['nope', $line->id]).'?t=x')->assertStatus(404);
        $this->assertSame([], $this->bridge->accepted);
    }
}
