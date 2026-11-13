<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hr;

use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Hr\Models\StaffTimeClock;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-273: the payroll report/export is bounded by the STORE's calendar days, not
 * the app (UTC) day — a Riyadh shift clocked in at 01:00 local belongs to that
 * local date. The date range is validated too: malformed, reversed and
 * over-long (more than a quarter) ranges are rejected instead of 500 / silently
 * producing nonsense.
 */
class PayrollStoreDayTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $r;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();

        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $this->owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $this->r = Store::create([
            'user_id' => $this->owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        StaffMember::create([
            'owner_id' => $this->owner->id, 'store_id' => $this->r->id, 'name' => 'Sam', 'role' => 'waiter',
            'hourly_rate' => 10, 'email' => 's'.Str::random(4).'@x.com', 'password' => Hash::make('x'), 'active' => 1,
        ]);
    }

    /** Read the single staff row's "Worked hours" out of the payroll CSV. */
    private function workedHours(string $from, string $to): float
    {
        $csv = $this->actingAs($this->owner, 'web')
            ->get(route('stores.rota.payroll.csv').'?store_id='.$this->r->id.'&from='.$from.'&to='.$to)
            ->assertOk()
            ->streamedContent();

        $lines = array_values(array_filter(explode("\n", trim($csv)), fn ($line) => trim($line) !== ''));
        $data = array_combine(str_getcsv($lines[0]), str_getcsv($lines[1]));

        return (float) $data['Worked hours'];
    }

    private function csvUrl(string $query): string
    {
        return route('stores.rota.payroll.csv').'?store_id='.$this->r->id.'&'.$query;
    }

    public function test_local_midnight_clock_in_counts_on_the_store_day(): void
    {
        // Store is UTC+3 (no DST). 2026-03-10 01:00 local == 2026-03-09 22:00 UTC.
        StoreSettings::setMany((int) $this->r->id, ['timezone' => 'Asia/Riyadh']);

        StaffTimeClock::create([
            'staff_id' => StaffMember::where('store_id', $this->r->id)->value('id'),
            'store_id' => $this->r->id,
            'clock_in_at' => '2026-03-09 22:00:00',
            'clock_out_at' => '2026-03-09 23:00:00',
        ]);

        // Counted for the local day it actually started on...
        $this->assertSame(1.0, $this->workedHours('2026-03-10', '2026-03-10'));

        // ...and NOT for the UTC day that would have swept it in before.
        $this->assertSame(0.0, $this->workedHours('2026-03-09', '2026-03-09'));
    }

    public function test_malformed_from_date_is_rejected(): void
    {
        $this->actingAs($this->owner, 'web')
            ->getJson($this->csvUrl('from=x&to=2026-03-10'))
            ->assertStatus(422);
    }

    public function test_reversed_range_is_rejected(): void
    {
        $this->actingAs($this->owner, 'web')
            ->getJson($this->csvUrl('from=2026-03-20&to=2026-03-10'))
            ->assertStatus(422);
    }

    public function test_range_over_92_days_is_rejected(): void
    {
        $this->actingAs($this->owner, 'web')
            ->getJson($this->csvUrl('from=2026-01-01&to=2026-04-15'))
            ->assertStatus(422);
    }
}
