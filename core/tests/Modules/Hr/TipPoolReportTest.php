<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hr;

use App\Models\Order;
use App\Models\StaffMember;
use App\Models\StoreOption;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * K4 tip-pool report (TipPoolController): the "even" split must divide the pool across
 * REAL staff only. The NULL created_by_staff_id row is the storefront/unassigned bucket,
 * not a staff member — counting it diluted every real share and paid a slice to nobody.
 */
class TipPoolReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware();
    }

    public function test_even_split_excludes_the_unassigned_bucket(): void
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
        $a = StaffMember::create(['owner_id' => $owner->id, 'store_id' => $r->id, 'name' => 'Alice', 'email' => 'a'.Str::random(4).'@x.co', 'password' => Hash::make('secret1'), 'role' => 'waiter', 'active' => true]);
        $b = StaffMember::create(['owner_id' => $owner->id, 'store_id' => $r->id, 'name' => 'Bob', 'email' => 'b'.Str::random(4).'@x.co', 'password' => Hash::make('secret1'), 'role' => 'waiter', 'active' => true]);

        // 100 tips each to Alice and Bob, plus 100 on a storefront (unassigned) order.
        Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'completed', 'tip_amount' => 100, 'created_by_staff_id' => $a->id, 'created_at' => now()]);
        Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'completed', 'tip_amount' => 100, 'created_by_staff_id' => $b->id, 'created_at' => now()]);
        Order::create(['store_id' => $r->id, 'type' => 'takeaway', 'status' => 'completed', 'tip_amount' => 100, 'created_by_staff_id' => null, 'created_at' => now()]);

        $from = now()->subDay()->toDateString();
        $to = now()->addDay()->toDateString();

        $resp = $this->actingAs($owner)->get(route('reports.tips', [
            'rule' => 'even', 'export' => 'csv', 'from' => $from, 'to' => $to,
        ]))->assertOk();

        $content = $resp->streamedContent();
        $shares = [];
        foreach (array_filter(array_map('trim', explode("\n", $content))) as $line) {
            $cols = str_getcsv($line, escape: '\\');
            $name = ltrim($cols[0], "\xEF\xBB\xBF");
            if ($name === 'Staff') {
                continue;
            }
            $shares[$name] = (float) $cols[3]; // "Pooled share" column
        }

        // Pool of 300 split across the 2 real staff = 150 each; unassigned gets 0.
        // Before the fix: /3 = 100 each and 100 to the unassigned bucket.
        $this->assertEqualsWithDelta(150.0, $shares['Alice'] ?? -1, 0.01, 'Alice must get half the pool, not a third');
        $this->assertEqualsWithDelta(150.0, $shares['Bob'] ?? -1, 0.01, 'Bob must get half the pool, not a third');
        $this->assertEqualsWithDelta(0.0, $shares['Storefront / unassigned'] ?? -1, 0.01, 'the unassigned bucket must not be paid a share');
    }

    public function test_tip_days_are_store_local_across_timezones_and_dst(): void
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $istanbul = $this->storeInTimezone($owner, 'Europe/Istanbul'); // UTC+3, no DST
        $newYork = $this->storeInTimezone($owner, 'America/New_York'); // spring forward 2026-03-08

        // Istanbul 2026-03-10 23:30 local (20:30Z) belongs to the 10th.
        $this->tipOrderAtUtc($istanbul, '2026-03-10 20:30:00', 10);
        // Istanbul 2026-03-11 00:30 local is still the 10th in UTC, but belongs to the 11th.
        $this->tipOrderAtUtc($istanbul, '2026-03-10 21:30:00', 1000);
        // New York 2026-03-08 23:30 local (EDT, 23-hour day) = 03:30Z on the 9th UTC: belongs to the 8th.
        $this->tipOrderAtUtc($newYork, '2026-03-09 03:30:00', 100);
        // New York 2026-03-07 23:30 local (EST) = 04:30Z on the 8th UTC: belongs to the 7th.
        $this->tipOrderAtUtc($newYork, '2026-03-08 04:30:00', 1000);

        $response = $this->actingAs($owner)->get(route('reports.tips', ['from' => '2026-03-08', 'to' => '2026-03-10']))->assertOk();

        // Only Istanbul 10th (10) and New York 8th (100) fall inside the store-local window.
        $response->assertViewHas('totalTips', 110.0);
    }

    private function storeInTimezone(User $owner, string $timezone): Store
    {
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'timezone', $timezone);

        return $store;
    }

    private function tipOrderAtUtc(Store $store, string $utc, int $tip): Order
    {
        return Order::create([
            'store_id' => $store->id, 'type' => 'takeaway', 'status' => 'completed', 'tip_amount' => $tip,
            'created_by_staff_id' => null, 'created_at' => Carbon::parse($utc, 'UTC'),
        ]);
    }
}
