<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Agents\Models\PhoneLine;
use Juwri\Modules\Agents\Services\Telephony\TelephonyRegistry;
use Tests\Support\TestPlans;
use Tests\TestCase;

class VoiceInboundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function line(string $provider = 'twilio', string $number = '+15551234567'): PhoneLine
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
        Credential::create([
            'store_id' => $r->id, 'feature' => $provider, 'provider' => $provider,
            'secrets' => ['auth_token' => 'twilio-token'], 'active' => true,
        ]);

        return PhoneLine::create([
            'store_id' => $r->id, 'provider' => $provider, 'number' => $number,
            'active' => true, 'greeting' => 'Welcome to R', 'orders_enabled' => true,
        ]);
    }

    /** Twilio signs base64(HMAC-SHA1(fullUrl + sorted params, token)). */
    private function twilioSig(string $url, array $params, string $token): string
    {
        ksort($params);
        $data = $url;
        foreach ($params as $k => $v) {
            $data .= $k.$v;
        }

        return base64_encode(hash_hmac('sha1', $data, $token, true));
    }

    public function test_signed_incoming_call_answers_with_conversation_relay(): void
    {
        $line = $this->line();
        $url = route('voice.incoming', 'twilio');
        $params = ['To' => $line->number, 'From' => '+15559998888', 'CallSid' => 'CA1'];
        $sig = $this->twilioSig($url, $params, 'twilio-token');

        $res = $this->call('POST', $url, $params, [], [], ['HTTP_X_TWILIO_SIGNATURE' => $sig]);
        $res->assertOk();
        $this->assertStringContainsString('ConversationRelay', $res->getContent());
        $this->assertStringContainsString('Welcome to R', $res->getContent());
    }

    public function test_unsigned_call_rejected(): void
    {
        $line = $this->line();
        $this->post(route('voice.incoming', 'twilio'), ['To' => $line->number, 'From' => '+1'])
            ->assertStatus(401);
    }

    public function test_unknown_number_404(): void
    {
        $line = $this->line();
        $url = route('voice.incoming', 'twilio');
        $params = ['To' => '+10000000000', 'From' => '+1'];
        $sig = $this->twilioSig($url, $params, 'twilio-token');
        $this->call('POST', $url, $params, [], [], ['HTTP_X_TWILIO_SIGNATURE' => $sig])->assertStatus(404);
    }

    public function test_fake_provider_answers_without_signature(): void
    {
        $line = $this->line('fake', '+1777');
        $res = $this->post(route('voice.incoming', 'fake'), ['To' => '+1777', 'From' => '+1', 'CallSid' => 'CAF1'])->assertOk();
        $this->assertStringContainsString('bridge', $res->getContent());
    }

    /** AUDIT-authz #5: the always-true fake provider exists only in local/testing. */
    public function test_fake_provider_is_off_outside_local_and_testing(): void
    {
        $this->line('fake', '+1778');
        $this->app['env'] = 'production';

        $this->assertNull(TelephonyRegistry::get('fake'));
        $res = $this->post(route('voice.incoming', 'fake'), ['To' => '+1778', 'From' => '+1', 'CallSid' => 'CAF2']);
        $this->assertStringNotContainsString('bridge', $res->getContent());
        $this->assertGreaterThanOrEqual(400, $res->getStatusCode());
        $this->assertNotNull(TelephonyRegistry::get('twilio'));
    }
}
