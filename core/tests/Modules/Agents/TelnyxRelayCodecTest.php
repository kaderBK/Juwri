<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Agents\Models\CallLog;
use Juwri\Modules\Agents\Models\PhoneLine;
use Juwri\Modules\Agents\Services\Telephony\Bridge\TelnyxRelayBridge;
use Juwri\Modules\Agents\Services\Telephony\Bridge\TelnyxRelayCodec;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * V-05 Telnyx AI text-relay codec. Sample frames follow the public docs:
 *  - media streaming: https://developers.telnyx.com/docs/voice/programmable-voice/media-streaming
 *  - AI assistants:   https://developers.telnyx.com/docs/voice/ai-assistants
 * The line has orders_enabled=false so VoiceSession::turn hands off without AI calls.
 */
class TelnyxRelayCodecTest extends TestCase
{
    use RefreshDatabase;

    private function line(bool $orders = false): PhoneLine
    {
        $plan = TestPlans::create([
            'name' => 'P' . Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => ['restaurant_limit' => 999],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-' . Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return PhoneLine::create([
            'store_id' => $r->id, 'provider' => 'telnyx', 'number' => '+1555' . rand(1000, 9999),
            'active' => true, 'greeting' => 'Hi', 'orders_enabled' => $orders, 'language' => 'ar',
        ]);
    }

    /** Start frame copied from the media-streaming docs. */
    private function startFrame(string $callControlId = 'v3:abc', string $from = '+13122010094'): string
    {
        return (string) json_encode([
            'event' => 'start',
            'sequence_number' => '1',
            'start' => [
                'call_control_id' => $callControlId,
                'call_session_id' => 'ff55a038-6f5d-11ef-9692-02420aeffb1f',
                'from' => $from,
                'to' => '+13122123456',
                'media_format' => ['encoding' => 'PCMU', 'sample_rate' => 8000, 'channels' => 1],
            ],
            'stream_id' => '32DE0DEA-53CB-4B21-89A4-9E1819C043BC',
        ]);
    }

    /** Caller utterance item copied from the AI conversation-event-stream docs. */
    private function userItemFrame(string $text = 'do you have pizza'): string
    {
        return (string) json_encode([
            'type' => 'conversation.item.created',
            'item' => [
                'type' => 'message',
                'role' => 'user',
                'content' => [['type' => 'input_text', 'text' => $text]],
            ],
        ]);
    }

    public function test_start_and_user_transcript_produce_assistant_frame(): void
    {
        $codec = new TelnyxRelayCodec($this->line());

        $this->assertNull($codec->decode($this->startFrame()));
        $this->assertTrue($codec->started());
        $this->assertSame('v3:abc', $codec->callControlId());
        $this->assertSame('+13122010094', $codec->callerNumber());

        $reply = $codec->decode($this->userItemFrame());
        $this->assertNotNull($reply);

        $frame = json_decode((string) $reply, true);
        $this->assertSame(TelnyxRelayCodec::REPLY_TYPE, $frame['type']);
        $this->assertSame('message', $frame['item']['type']);
        $this->assertSame('assistant', $frame['item']['role']);
        $this->assertSame(__('Let me put you through to a team member.'), $frame['item']['content'][0]['text']);

        $log = CallLog::where('call_sid', 'v3:abc')->first();
        $this->assertNotNull($log);
        $this->assertSame('+13122010094', $log->from_number);
    }

    public function test_assistant_and_tool_items_are_ignored(): void
    {
        $codec = new TelnyxRelayCodec($this->line());
        $codec->decode($this->startFrame());

        $assistant = (string) json_encode([
            'type' => 'conversation.item.created',
            'item' => ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'hi']]],
        ]);

        $this->assertNull($codec->decode($assistant));
        $this->assertNull($codec->decode($this->userItemFrame('   ')));
        $this->assertSame(0, CallLog::count());
    }

    public function test_ai_call_initiated_and_realtime_transcript_shapes(): void
    {
        $codec = new TelnyxRelayCodec($this->line());

        // telnyx.call.answered (AI event stream) — call ids at the top level (assumed).
        $this->assertNull($codec->decode((string) json_encode([
            'type' => 'telnyx.call.answered',
            'call_control_id' => 'v3:t2',
            'from' => '+13125550001',
        ])));
        $this->assertSame('v3:t2', $codec->callControlId());

        // Realtime conversations transcription-completed event.
        $reply = $codec->decode((string) json_encode([
            'type' => 'conversation.item.input_audio_transcription.completed',
            'transcript' => 'What are your business hours?',
        ]));
        $this->assertNotNull($reply);
        $this->assertSame('v3:t2', (string) CallLog::where('call_sid', 'v3:t2')->value('call_sid'));
    }

    public function test_assumed_text_relay_transcript_shapes(): void
    {
        $codec = new TelnyxRelayCodec($this->line());
        $codec->decode($this->startFrame('v3:t3'));

        // Nested `transcript.text` shape (docs do not fix a text-relay frame; assumed).
        $this->assertNotNull($codec->decode((string) json_encode([
            'event' => 'transcript',
            'transcript' => ['text' => 'one large pizza'],
        ])));

        // Nested `transcription.text` shape.
        $this->assertNotNull($codec->decode((string) json_encode([
            'event' => 'transcription',
            'transcription' => ['text' => 'and a coke'],
        ])));
    }

    public function test_stop_frame_finalizes_the_call(): void
    {
        $codec = new TelnyxRelayCodec($this->line());
        $codec->decode($this->startFrame('v3:t4'));
        $codec->decode($this->userItemFrame());
        $this->assertSame('handoff', CallLog::where('call_sid', 'v3:t4')->value('outcome'));

        // Media-streaming stop frame.
        $this->assertNull($codec->decode((string) json_encode([
            'event' => 'stop',
            'sequence_number' => '5',
            'stop' => ['call_control_id' => 'v3:t4'],
            'stream_id' => '32DE0DEA',
        ])));
        $this->assertSame('handoff', CallLog::where('call_sid', 'v3:t4')->value('outcome'));

        // AI hangup / session-end frames also end the call and are idempotent.
        $this->assertNull($codec->decode((string) json_encode(['type' => 'telnyx.call.hangup', 'cause' => 'normal_clearing'])));
        $this->assertNull($codec->decode((string) json_encode(['type' => 'session.ended', 'reason' => 'normal'])));
        $this->assertSame('handoff', CallLog::where('call_sid', 'v3:t4')->value('outcome'));
    }

    public function test_malformed_and_unknown_frames_are_ignored_safely(): void
    {
        $codec = new TelnyxRelayCodec($this->line());

        $this->assertNull($codec->decode('not json'));
        $this->assertNull($codec->decode('{"type":'));
        $this->assertNull($codec->decode('null'));
        $this->assertNull($codec->decode('123'));
        $this->assertNull($codec->decode('[]'));
        $this->assertNull($codec->decode((string) json_encode(['type' => 'response.text.delta', 'delta' => 'hi'])));
        $this->assertNull($codec->decode((string) json_encode(['event' => 'media', 'media' => ['payload' => 'x']])));
        $this->assertNull($codec->decode((string) json_encode(['event' => 'connected', 'version' => '1.0.0'])));
        $this->assertSame(0, CallLog::count());
    }

    public function test_utterance_before_start_is_dropped(): void
    {
        $codec = new TelnyxRelayCodec($this->line());
        $this->assertNull($codec->decode($this->userItemFrame()));
        $this->assertSame(0, CallLog::count());
    }

    public function test_bridge_requires_a_websocket_upgrade_and_holds_the_codec(): void
    {
        $line = $this->line();
        $bridge = new TelnyxRelayBridge;

        $response = $bridge->accept(
            Request::create('/voice/telnyx/bridge/' . $line->id, 'GET'),
            'telnyx',
            $line,
            ['p' => 'telnyx', 'l' => (int) $line->id, 's' => (int) $line->store_id, 'c' => 'v3:abc', 'exp' => time() + 60, 'n' => 'n'],
        );

        $this->assertSame(Response::HTTP_UPGRADE_REQUIRED, $response->getStatusCode());
        $this->assertStringContainsString('websocket required', (string) $response->getContent());
        $this->assertInstanceOf(TelnyxRelayCodec::class, $bridge->codec());
    }
}
