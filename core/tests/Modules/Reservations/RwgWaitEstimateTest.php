<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Reservations;

use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use App\Models\WaitlistEntry;
use Juwri\Modules\Reservations\Services\Rwg\WaitEstimateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Settings\StoreSettings;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * google-partner Lane D (D2): WaitEstimateService math + BatchGetWaitEstimates shape.
 */
class RwgWaitEstimateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    private function makeRestaurant(): Store
    {
        $plan = TestPlans::create([
            'name' => 'Plan ' . Str::random(6), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999]), 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'Restaurant ' . Str::random(6);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title) . '-' . Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 Test St',
            'phone' => '1234567890', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function addWaiting(Store $r, int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            WaitlistEntry::create([
                'store_id' => $r->id, 'name' => 'G' . $i, 'party_size' => 2,
                'status' => 'waiting', 'quoted_wait' => 20,
            ]);
        }
    }

    /** Quote = (waiting + 1) × avg-turn — matches add()/publicJoin(). */
    public function test_quoted_wait_scales_with_queue_and_avg_turn(): void
    {
        $r = $this->makeRestaurant();
        $svc = new WaitEstimateService();

        // Empty queue → position 1 → 20 (default avg-turn).
        $this->assertSame(20, $svc->quotedWaitMinutesFor($r->id));

        $this->addWaiting($r, 2); // 2 ahead → position 3 → 60
        $this->assertSame(60, $svc->quotedWaitMinutesFor($r->id));

        // Option override respected.
        StoreSettings::set($r->id, 'waitlist_avg_turn', '15');
        $this->assertSame(45, $svc->quotedWaitMinutesFor($r->id)); // position 3 × 15
    }

    /** BatchGetWaitEstimates shape: one entry per (deduped, clamped) party size, seconds + parties-ahead. */
    public function test_batch_estimate_shape_and_dedupe(): void
    {
        $r = $this->makeRestaurant();
        StoreSettings::set($r->id, 'rwg_waitlist_enabled', '1');
        $this->addWaiting($r, 3); // 3 ahead → position 4 → 80 min → 4800s

        $out = new WaitEstimateService()->batchEstimate($r->id, [2, 2, 4, 0, 200, 6]);

        $this->assertSame('OPEN', $out['waitlist_status']);
        // 0 and 200 dropped (out of 1..99), 2 de-duped → [2,4,6].
        $this->assertSame([2, 4, 6], array_column($out['wait_estimate'], 'party_size'));
        $first = $out['wait_estimate'][0];
        $this->assertSame(3, $first['wait_length']['parties_ahead_count']);
        $this->assertSame(4800, $first['wait_length']['wait_length_seconds']);
    }

    /** waitlist_status is CLOSED until the store opts its RwG waitlist in. */
    public function test_status_closed_when_not_opted_in(): void
    {
        $r = $this->makeRestaurant();
        $svc = new WaitEstimateService();

        $this->assertSame('CLOSED', $svc->statusFor($r->id));
        $this->assertSame('CLOSED', $svc->batchEstimate($r->id, [2])['waitlist_status']);

        StoreSettings::set($r->id, 'rwg_waitlist_enabled', '1');
        $this->assertSame('OPEN', $svc->statusFor($r->id));
    }
}
