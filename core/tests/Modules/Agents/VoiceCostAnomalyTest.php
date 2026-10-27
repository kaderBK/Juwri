<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\PlatformAlert;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Agents\Models\CallLog;
use Juwri\Modules\Agents\Services\Telephony\VoiceCostAnomaly;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-09 V-20: the voice cost-anomaly scan (billed minutes as the cost proxy until V-12 lands). A store alerts when
 * today's phone-agent minutes are both >= 3x its previous-7-day average and >= 30 minutes; a store with a zero
 * baseline alerts at 60 minutes; the alert is de-duped per day; stores are judged independently.
 */
class VoiceCostAnomalyTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $now;

    private Store $store;

    private Store $otherStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = Carbon::parse('2026-06-15 12:00:00');
        Carbon::setTestNow($this->now);
        $this->store = $this->makeStore();
        $this->otherStore = $this->makeStore();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeStore(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeCall(int $storeId, int $seconds, Carbon $at): void
    {
        CallLog::create([
            'store_id' => $storeId, 'provider' => 'twilio', 'call_sid' => 'CA-'.Str::random(16),
            'outcome' => 'completed', 'duration_seconds' => $seconds,
            'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    /** One call a day for each of the previous 7 full days. */
    private function baseline(int $storeId, int $secondsPerDay = 600): void
    {
        for ($offset = 1; $offset <= 7; $offset++) {
            $this->makeCall($storeId, $secondsPerDay, $this->now->copy()->subDays($offset)->setTime(10, 0));
        }
    }

    /** @return list<array{store_id:int,today:int,avg:float}> */
    private function check(): array
    {
        return (new VoiceCostAnomaly)->check($this->now);
    }

    private function alerts(): int
    {
        return PlatformAlert::where('kind', 'voice_cost_anomaly')->count();
    }

    public function test_three_times_spike_alerts(): void
    {
        $this->baseline($this->store->id); // 10 min/day => avg 10
        $this->makeCall($this->store->id, 1800, $this->now->copy()->setTime(11, 0)); // 30 today

        $anomalies = $this->check();

        $this->assertSame(1, $this->alerts());
        $this->assertSame([['store_id' => $this->store->id, 'today' => 30, 'avg' => 10.0]], $anomalies);
    }

    public function test_normal_day_does_not_alert(): void
    {
        $this->baseline($this->store->id); // avg 10
        $this->makeCall($this->store->id, 1200, $this->now->copy()->setTime(11, 0)); // 20 today

        $this->assertSame([], $this->check());
        $this->assertSame(0, $this->alerts());
    }

    public function test_below_the_thirty_minute_floor_does_not_alert(): void
    {
        $this->baseline($this->store->id, 60); // 1 min/day => avg 1
        $this->makeCall($this->store->id, 1200, $this->now->copy()->setTime(11, 0)); // 20 today (>= 3x but under floor)

        $this->assertSame([], $this->check());
        $this->assertSame(0, $this->alerts());
    }

    public function test_zero_baseline_store_over_sixty_minutes_alerts(): void
    {
        $this->makeCall($this->store->id, 3600, $this->now->copy()->setTime(11, 0)); // 60 today, no history

        $anomalies = $this->check();

        $this->assertSame(1, $this->alerts());
        $this->assertSame([['store_id' => $this->store->id, 'today' => 60, 'avg' => 0.0]], $anomalies);
    }

    public function test_second_run_same_day_does_not_create_a_duplicate_row(): void
    {
        $this->baseline($this->store->id);
        $this->makeCall($this->store->id, 1800, $this->now->copy()->setTime(11, 0));

        $this->assertCount(1, $this->check());
        $this->assertCount(1, $this->check());

        $this->assertSame(1, $this->alerts());
    }

    public function test_stores_are_judged_separately(): void
    {
        $this->baseline($this->store->id);
        $this->makeCall($this->store->id, 1800, $this->now->copy()->setTime(11, 0)); // spike

        $this->baseline($this->otherStore->id);
        $this->makeCall($this->otherStore->id, 600, $this->now->copy()->setTime(11, 0)); // same as baseline

        $anomalies = $this->check();

        $this->assertSame(1, $this->alerts());
        $this->assertSame([['store_id' => $this->store->id, 'today' => 30, 'avg' => 10.0]], $anomalies);
    }
}
