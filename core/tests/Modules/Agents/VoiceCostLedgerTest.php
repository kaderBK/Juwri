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
use Juwri\Modules\Agents\Models\CallLog;
use Juwri\Modules\Agents\Models\PhoneLine;
use Juwri\Modules\Agents\Services\Telephony\VoiceCostLedger;
use Juwri\Modules\Agents\Services\Telephony\VoiceSession;
use Juwri\Modules\Messaging\Models\MessageCost;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-09 V-12: one `message_costs` row per finished call, from the provider's final
 * duration (billed minutes × the serving cost per minute), idempotent per call SID;
 * the monthly plan view (included minutes → overage) and the provider status webhook
 * that turns a final CallStatus into that row.
 */
class VoiceCostLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function line(string $number): array
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
        $line = PhoneLine::create([
            'store_id' => $store->id, 'provider' => 'fake', 'number' => $number,
            'active' => true, 'orders_enabled' => true,
        ]);

        return [$store, $line];
    }

    private function callLog(Store $store, PhoneLine $line, string $sid, ?int $duration = null): CallLog
    {
        return CallLog::create([
            'store_id' => $store->id, 'phone_line_id' => $line->id, 'provider' => $line->provider,
            'call_sid' => $sid, 'from_number' => '+15559990000', 'outcome' => 'in_progress',
            'transcript' => [], 'duration_seconds' => $duration,
        ]);
    }

    // ---- finalize → one cost row, idempotent per call SID ---------------------------

    public function test_finalize_writes_one_cost_row_and_replays_write_nothing(): void
    {
        [$store, $line] = $this->line('+17771001');
        $this->callLog($store, $line, 'CA-COST-1');

        VoiceSession::finalize('CA-COST-1', 'hangup', 61);

        $row = MessageCost::where('channel', 'voice')->where('provider_ref', 'CA-COST-1')->first();
        $this->assertNotNull($row);
        $this->assertSame('voice', $row->channel);
        $this->assertSame('service', $row->category);
        $this->assertSame('phone_agent', $row->feature);
        $this->assertSame((int) $store->id, (int) $row->store_id);
        $this->assertSame(2, (int) $row->units, 'every started minute is billed');
        $this->assertEqualsWithDelta(24.0, (float) $row->cost_minor, 0.0001, '2 min × $0.12 = 24 minor');

        VoiceSession::finalize('CA-COST-1', 'hangup', 61);
        $this->assertSame(1, MessageCost::where('provider_ref', 'CA-COST-1')->count());
    }

    public function test_a_zero_second_call_writes_no_cost_row(): void
    {
        [$store, $line] = $this->line('+17771002');
        $this->callLog($store, $line, 'CA-COST-0');

        VoiceSession::finalize('CA-COST-0', 'hangup', 0);

        $this->assertSame(0, MessageCost::where('provider_ref', 'CA-COST-0')->count());
    }

    // ---- monthUsage: plan included minutes then overage -----------------------------

    public function test_month_usage_reports_minutes_overage_and_charge(): void
    {
        [$store, $line] = $this->line('+17771003');
        $this->callLog($store, $line, 'CA-COST-BIG');

        VoiceSession::finalize('CA-COST-BIG', 'hangup', 601 * 60);

        $usage = VoiceCostLedger::monthUsage((int) $store->id);
        $this->assertSame(601, $usage['minutes']);
        $this->assertSame(600, $usage['included']);
        $this->assertSame(1, $usage['overage_minutes']);
        $this->assertEqualsWithDelta(0.20, $usage['overage_charge'], 0.001);
        $this->assertEqualsWithDelta(149.0, $usage['plan_price'], 0.001);
        $this->assertEqualsWithDelta(72.12, $usage['cost'], 0.001, '601 min × $0.12');
        $this->assertSame('USD', $usage['currency']);
    }

    // ---- status webhook: final CallStatus → finalize + cost row ---------------------

    public function test_status_webhook_finalizes_an_owned_call_and_writes_the_cost(): void
    {
        [$store, $line] = $this->line('+17771004');
        $log = $this->callLog($store, $line, 'CA-WH-1');

        $this->post(route('voice.status', 'fake'), [
            'To' => $line->number, 'CallSid' => 'CA-WH-1', 'CallStatus' => 'completed', 'CallDuration' => 125,
        ])->assertStatus(204);

        $this->assertSame(125, (int) $log->fresh()->duration_seconds);
        $row = MessageCost::where('provider_ref', 'CA-WH-1')->first();
        $this->assertNotNull($row);
        $this->assertSame((int) $store->id, (int) $row->store_id);
        $this->assertSame(3, (int) $row->units, '125s → 3 billed minutes');
    }

    public function test_status_webhook_ignores_a_call_owned_by_another_store(): void
    {
        [$storeA, $lineA] = $this->line('+17771005');
        [$storeB, $lineB] = $this->line('+17771006');
        $logB = $this->callLog($storeB, $lineB, 'CA-FOREIGN', 5);

        $this->post(route('voice.status', 'fake'), [
            'To' => $lineA->number, 'CallSid' => 'CA-FOREIGN', 'CallStatus' => 'completed', 'CallDuration' => 125,
        ])->assertStatus(204);

        $this->assertSame(5, (int) $logB->fresh()->duration_seconds, 'a foreign call is never touched');
        $this->assertSame(0, MessageCost::where('provider_ref', 'CA-FOREIGN')->count());
    }

    public function test_status_webhook_ignores_a_non_final_state(): void
    {
        [$store, $line] = $this->line('+17771007');
        $log = $this->callLog($store, $line, 'CA-IP');

        $this->post(route('voice.status', 'fake'), [
            'To' => $line->number, 'CallSid' => 'CA-IP', 'CallStatus' => 'in-progress', 'CallDuration' => 125,
        ])->assertStatus(204);

        $this->assertNull($log->fresh()->duration_seconds);
        $this->assertSame(0, MessageCost::where('provider_ref', 'CA-IP')->count());
    }

    public function test_status_webhook_unknown_provider_is_404(): void
    {
        $this->post(route('voice.status', 'nope'), [])->assertStatus(404);
    }

    public function test_status_webhook_unknown_line_is_404(): void
    {
        $this->post(route('voice.status', 'fake'), ['To' => '+10000000000'])->assertStatus(404);
    }
}
