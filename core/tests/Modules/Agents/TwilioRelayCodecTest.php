<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Agents\Models\PhoneLine;
use Juwri\Modules\Agents\Services\Telephony\Bridge\TwilioRelayBridge;
use Juwri\Modules\Agents\Services\Telephony\Bridge\TwilioRelayCodec;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-09 V-04: ConversationRelay frame codec. Sample frames mirror the public reference:
 * https://www.twilio.com/docs/voice/conversationrelay/websocket-messages
 */
class TwilioRelayCodecTest extends TestCase
{
    use RefreshDatabase;

    /** Line with ordering off so VoiceSession::turn never reaches the AI/tools. */
    private function line(): PhoneLine
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
            'store_id' => $r->id, 'provider' => 'twilio', 'number' => '+1555'.rand(1000, 9999),
            'active' => true, 'greeting' => 'Hi', 'orders_enabled' => false, 'language' => 'ar',
        ]);
    }

    private function sendSetup(TwilioRelayCodec $codec, string $callSid = 'CA00000000000000000000000000000000', string $from = '+18005550100'): void
    {
        $frame = json_encode([
            'type' => 'setup', 'sessionId' => 'VX1', 'accountSid' => 'AC1',
            'callSid' => $callSid, 'from' => $from, 'to' => '+18005550101',
            'callType' => 'PSTN', 'direction' => 'inbound', 'callStatus' => 'RINGING',
        ]);
        $this->assertNull($codec->decode((string) $frame));
    }

    private function prompt(string $text, bool $last = true): string
    {
        return (string) json_encode([
            'type' => 'prompt', 'voicePrompt' => $text, 'lang' => 'en-US', 'last' => $last,
        ]);
    }

    public function test_setup_stores_call_identity(): void
    {
        $codec = new TwilioRelayCodec($this->line());
        $this->sendSetup($codec, 'CA42', '+15550001111');

        $this->assertSame('CA42', $codec->callSid());
        $this->assertSame('+15550001111', $codec->from());
    }

    public function test_prompt_produces_a_text_frame_from_the_voice_session(): void
    {
        $codec = new TwilioRelayCodec($this->line());
        $this->sendSetup($codec);

        $reply = $codec->decode($this->prompt('Hi! Can you tell me about life?'));
        $this->assertNotNull($reply);
        $this->assertSame([
            'type' => 'text',
            'token' => __('Let me put you through to a team member.'),
            'last' => true,
        ], json_decode($reply, true));
    }

    public function test_partial_prompts_buffer_until_the_last_fragment(): void
    {
        $codec = new TwilioRelayCodec($this->line());
        $this->sendSetup($codec);

        $this->assertNull($codec->decode($this->prompt('I would like', last: false)));
        $this->assertSame('I would like', $codec->pending());
        $this->assertNull($codec->decode($this->prompt('a large pizza', last: false)));
        $this->assertSame('I would like a large pizza', $codec->pending());

        $this->assertNotNull($codec->decode($this->prompt('please', last: true)));
        $this->assertSame('', $codec->pending());
    }

    public function test_interrupt_clears_the_buffered_utterance(): void
    {
        $codec = new TwilioRelayCodec($this->line());
        $this->sendSetup($codec);
        $codec->decode($this->prompt('Life is a complex', last: false));
        $this->assertNotSame('', $codec->pending());

        $interrupt = (string) json_encode([
            'type' => 'interrupt', 'utteranceUntilInterrupt' => 'Life is a complex set of', 'durationUntilInterruptMs' => 460,
        ]);
        $this->assertNull($codec->decode($interrupt));
        $this->assertSame('', $codec->pending());
    }

    public function test_prompt_before_setup_is_ignored(): void
    {
        $codec = new TwilioRelayCodec($this->line());

        $this->assertNull($codec->decode($this->prompt('hello?')));
    }

    public function test_malformed_and_unknown_frames_are_ignored_without_throwing(): void
    {
        $codec = new TwilioRelayCodec($this->line());
        $this->sendSetup($codec);

        $this->assertNull($codec->decode('{not json'));
        $this->assertNull($codec->decode(''));
        $this->assertNull($codec->decode('null'));
        $this->assertNull($codec->decode('[]'));
        $this->assertNull($codec->decode('"hello"'));
        $this->assertNull($codec->decode((string) json_encode(['type' => 'wat'])));
        $this->assertNull($codec->decode((string) json_encode(['no' => 'type'])));
    }

    public function test_dtmf_and_error_frames_are_ignored(): void
    {
        $codec = new TwilioRelayCodec($this->line());
        $this->sendSetup($codec);

        $this->assertNull($codec->decode((string) json_encode(['type' => 'dtmf', 'digit' => '1'])));
        $this->assertNull($codec->decode((string) json_encode(['type' => 'error', 'description' => 'Invalid message received'])));
    }

    public function test_end_frame_when_told_to_end(): void
    {
        $codec = new TwilioRelayCodec($this->line());
        $this->sendSetup($codec);
        $codec->decode($this->prompt('hold on', last: false));

        $this->assertSame('{"type":"end"}', $codec->end());
        $this->assertSame('', $codec->pending());
    }

    public function test_bridge_accept_returns_426_and_holds_the_codec(): void
    {
        $line = $this->line();
        $bridge = new TwilioRelayBridge;

        $response = $bridge->accept(
            Request::create('/voice/twilio/bridge/'.$line->id, 'GET'),
            'twilio',
            $line,
            ['p' => 'twilio', 'l' => (int) $line->id, 's' => (int) $line->store_id, 'c' => 'CA9', 'exp' => 0, 'n' => 'nonce']
        );

        $body = json_decode((string) $response->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame(426, $response->getStatusCode());
        $this->assertSame('websocket required', $body['error']);
        $this->assertInstanceOf(TwilioRelayCodec::class, $bridge->codec);
    }
}
