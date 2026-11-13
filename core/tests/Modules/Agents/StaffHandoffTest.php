<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Agents\Models\AgentConversation;
use Juwri\Modules\Agents\Models\CallLog;
use Juwri\Modules\Agents\Models\PhoneLine;
use Juwri\Modules\Agents\Services\Telephony\Handoff\StaffHandoff;
use Juwri\Modules\Messaging\Contracts\MessageQueue;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-09 V-13: staff handoff. Warm transfer with a whispered summary inside hours;
 * callback + conversation flag + staff notification otherwise; PII-redacted summary;
 * store isolation; XML escaping. Runs on the Fake telephony provider (no network).
 */
class StaffHandoffTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, mixed> $line */
    private function storeWithLine(array $line = []): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'ai_chatbot' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $line = PhoneLine::create(array_merge([
            'store_id' => $store->id, 'provider' => 'fake', 'number' => '+1555'.rand(1000000, 9999999),
            'active' => true, 'orders_enabled' => true,
        ], $line));

        return [$store, $line];
    }

    /** @param list<array{role: string, text: string}> $transcript */
    private function callLog(Store $store, PhoneLine $line, string $sid, array $transcript, string $outcome = 'in_progress'): CallLog
    {
        return CallLog::create([
            'store_id' => $store->id, 'phone_line_id' => $line->id, 'provider' => $line->provider,
            'call_sid' => $sid, 'from_number' => '+15559990000', 'outcome' => $outcome, 'transcript' => $transcript,
        ]);
    }

    private function hoursAroundNow(): string
    {
        $now = Carbon::now('UTC');

        return $now->copy()->subHour()->format('H:i').'-'.$now->copy()->addHour()->format('H:i');
    }

    private function hoursAwayFromNow(): string
    {
        $now = Carbon::now('UTC');

        return $now->copy()->addHours(2)->format('H:i').'-'.$now->copy()->addHours(3)->format('H:i');
    }

    public function test_transfer_inside_hours_carries_whisper_summary(): void
    {
        [$store, $line] = $this->storeWithLine([
            'handoff_number' => '+15550001111',
            'active_hours' => $this->hoursAroundNow(),
        ]);
        $log = $this->callLog($store, $line, 'CA-V13-1', [
            ['role' => 'caller', 'text' => 'I want two large pizzas'],
        ]);

        $out = StaffHandoff::handle($line, $log, 'wants_human');

        $this->assertSame('transfer', $out['mode']);
        $this->assertIsArray($out['payload']);
        $this->assertSame('+15550001111', $out['payload'][0]['transfer']);
        $this->assertStringContainsString('two large pizzas', $out['payload'][0]['whisper']);
        $this->assertStringContainsString('two large pizzas', $out['summary']);
        // A transfer is live — the log stays open and no callback is recorded.
        $this->assertSame('in_progress', $log->fresh()->outcome);
    }

    public function test_outside_hours_falls_back_to_callback_flag_and_notification(): void
    {
        [$store, $line] = $this->storeWithLine([
            'handoff_number' => '+15550001111',
            'active_hours' => $this->hoursAwayFromNow(),
        ]);
        $log = $this->callLog($store, $line, 'CA-V13-2', [
            ['role' => 'caller', 'text' => 'I need to speak to a manager'],
        ]);
        // The agent turn created the voice conversation earlier in the call.
        AgentConversation::create([
            'store_id' => $store->id, 'channel' => 'voice',
            'session_key' => substr(sha1('CA-V13-2'), 0, 40), 'locale' => 'en',
        ]);

        $notified = null;
        $this->mock(MessageQueue::class, function ($mock) use (&$notified) {
            $mock->shouldReceive('staffPush')->once()->andReturnUsing(function (...$args) use (&$notified) {
                $notified = $args;
            });
        });

        $out = StaffHandoff::handle($line, $log, 'complaint');

        $this->assertSame('callback', $out['mode']);
        $this->assertNull($out['payload']);
        $this->assertSame('handoff', $log->fresh()->outcome);

        $conversation = AgentConversation::where('store_id', $store->id)->where('channel', 'voice')->first();
        $this->assertNotNull($conversation);
        $this->assertTrue($conversation->meta['handoff']);
        $this->assertTrue($conversation->meta['callback_requested']);
        $this->assertSame('complaint', $conversation->meta['handoff_reason']);

        $this->assertNotNull($notified);
        $this->assertSame((int) $store->id, $notified[0]);
        $this->assertStringContainsString('manager', $notified[2]);
    }

    public function test_no_handoff_number_falls_back_to_callback(): void
    {
        [$store, $line] = $this->storeWithLine(['active_hours' => null]);
        $log = $this->callLog($store, $line, 'CA-V13-3', [
            ['role' => 'caller', 'text' => 'hello'],
        ]);

        $this->mock(MessageQueue::class, fn ($mock) => $mock->shouldReceive('staffPush')->once());

        $out = StaffHandoff::handle($line, $log, 'wants_human');

        $this->assertSame('callback', $out['mode']);
        $this->assertNull($out['payload']);
        $this->assertSame('handoff', $log->fresh()->outcome);
    }

    public function test_summary_redacts_pii_and_is_capped(): void
    {
        [$store, $line] = $this->storeWithLine([
            'handoff_number' => '+15550001111',
            'active_hours' => $this->hoursAroundNow(),
        ]);
        $long = str_repeat('please call me at +15551234567 or email a@b.com about my card 4111111111111111 ', 6);
        $log = $this->callLog($store, $line, 'CA-V13-4', [['role' => 'caller', 'text' => $long]]);

        $out = StaffHandoff::handle($line, $log, 'wants_human');
        $whisper = $out['payload'][0]['whisper'];

        $this->assertStringNotContainsString('+15551234567', $whisper);
        $this->assertStringNotContainsString('a@b.com', $whisper);
        $this->assertStringNotContainsString('4111111111111111', $whisper);
        $this->assertStringContainsString('[phone]', $whisper);
        $this->assertStringContainsString('[email]', $whisper);
        $this->assertLessThanOrEqual(StaffHandoff::MAX_SUMMARY, mb_strlen($whisper));
    }

    public function test_store_isolation_line_a_never_reads_log_of_b(): void
    {
        [$storeA, $lineA] = $this->storeWithLine(['handoff_number' => '+15550002222', 'active_hours' => null]);
        [$storeB, $lineB] = $this->storeWithLine(['handoff_number' => '+15550003333', 'active_hours' => null]);
        $logB = $this->callLog($storeB, $lineB, 'CA-V13-B', [
            ['role' => 'caller', 'text' => 'secret B order'],
        ]);

        $this->mock(MessageQueue::class, fn ($mock) => $mock->shouldReceive('staffPush')->never());

        $out = StaffHandoff::handle($lineA, $logB, 'wants_human');

        $this->assertSame('callback', $out['mode']);
        $this->assertSame('', $out['summary']);
        // Nothing about store B's call was touched.
        $this->assertSame('in_progress', $logB->fresh()->outcome);
        $this->assertSame(0, AgentConversation::where('store_id', $storeB->id)->count());
        $this->assertSame(0, AgentConversation::where('store_id', $storeA->id)->count());
    }

    public function test_transfer_markup_escapes_number_and_summary(): void
    {
        [$store, $line] = $this->storeWithLine([
            'provider' => 'twilio',
            'handoff_number' => '+1<2>&"3',
            'active_hours' => null,
        ]);
        $log = $this->callLog($store, $line, 'CA-V13-5', [
            ['role' => 'caller', 'text' => 'pizza & wings <hot>'],
        ]);

        $out = StaffHandoff::handle($line, $log, 'wants_human');
        $body = $out['payload'][0];

        $this->assertSame('transfer', $out['mode']);
        $this->assertStringContainsString('<Dial>', $body);
        $this->assertStringContainsString('<Number url=', $body);
        // The number is escaped; its raw markup is never emitted.
        $this->assertStringContainsString('&lt;', $body);
        $this->assertStringContainsString('&amp;', $body);
        $this->assertStringContainsString('&quot;', $body);
        $this->assertStringNotContainsString('<2>', $body);
    }

    public function test_triggers_enum_is_exactly_the_documented_set(): void
    {
        $this->assertSame(
            ['wants_human', 'failed_twice', 'complaint', 'tool_error', 'spend_cap', 'abusive', 'no_caller_id', 'no_pay_link'],
            StaffHandoff::TRIGGERS,
        );
    }
}
