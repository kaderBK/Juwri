<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hr;

use App\Models\StaffMember;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Hr\Models\LeaveRequest;
use Juwri\Modules\Hr\Models\LeaveType;
use Juwri\Modules\Hr\Models\StaffShift;
use Juwri\Modules\Hr\Services\Team\RotaUpgradeService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * SYSTEMS-RUN T-P3: availability conflicts, open-shift claim, swap flow, reminders.
 */
class RotaUpgradeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['settings.admin_allow_team_comms' => true]);
    }

    private function scenario(): array
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
        $a = StaffMember::create(['owner_id' => $owner->id, 'store_id' => $r->id, 'name' => 'A', 'email' => 'a'.Str::random(4).'@x.co', 'password' => Hash::make('secret1'), 'role' => 'waiter', 'active' => true]);
        $b = StaffMember::create(['owner_id' => $owner->id, 'store_id' => $r->id, 'name' => 'B', 'email' => 'b'.Str::random(4).'@x.co', 'password' => Hash::make('secret1'), 'role' => 'waiter', 'active' => true]);

        return [$owner, $r, $a, $b];
    }

    public function test_availability_and_leave_conflicts(): void
    {
        [$owner, $r, $a] = $this->scenario();
        $svc = new RotaUpgradeService;
        $shiftStart = now()->next(Carbon::MONDAY)->setTime(9, 0);
        $svc->setAvailability($a->id, (int) $shiftStart->dayOfWeek, 'unavailable');
        $this->assertContains('unavailable', $svc->conflicts($a->id, $shiftStart));

        LeaveType::ensureSeeded($r->id);
        $annual = LeaveType::where('store_id', $r->id)->where('name', 'Annual')->first();
        LeaveRequest::create(['store_id' => $r->id, 'staff_id' => $a->id, 'leave_type_id' => $annual->id,
            'from_date' => $shiftStart->toDateString(), 'to_date' => $shiftStart->toDateString(), 'status' => 'approved']);
        $this->assertContains('on_leave', $svc->conflicts($a->id, $shiftStart));
    }

    public function test_open_shift_claim_and_approve(): void
    {
        [$owner, $r, $a, $b] = $this->scenario();
        $shift = StaffShift::create(['store_id' => $r->id, 'staff_id' => $a->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHours(6)]);
        $svc = new RotaUpgradeService;
        $svc->openShift($shift);
        $svc->claim($shift->fresh(), $b->id);
        $this->assertSame('pending', $shift->fresh()->claim_status);
        $svc->approveClaim($shift->fresh());
        $this->assertSame($b->id, $shift->fresh()->staff_id);
        $this->assertFalse($shift->fresh()->is_open);
    }

    public function test_swap_flow_reassigns_shift(): void
    {
        [$owner, $r, $a, $b] = $this->scenario();
        $shift = StaffShift::create(['store_id' => $r->id, 'staff_id' => $a->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHours(6)]);
        $svc = new RotaUpgradeService;
        $swap = $svc->requestSwap($r->id, $shift, $a->id);
        $svc->acceptSwap($swap->fresh(), $b->id);
        $svc->approveSwap($swap->fresh());
        $this->assertSame($b->id, $shift->fresh()->staff_id);
        $this->assertSame('approved', $swap->fresh()->status);
    }

    public function test_owner_console_and_reminders(): void
    {
        [$owner, $r, $a] = $this->scenario();
        StaffShift::create(['store_id' => $r->id, 'staff_id' => $a->id, 'published' => true, 'starts_at' => now()->addHour(), 'ends_at' => now()->addHours(6)]);
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $settings = new class(['theme_color' => '#111', 'site_title' => 'T', 'meta_description' => 'T', 'social_share_image' => 'd.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'f.png'])
        {
            public function __construct(private array $d) {}

            public function __get($k): mixed
            {
                return $this->d[$k] ?? null;
            }

            public function __set($k, $v): void
            {
                $this->d[$k] = $v;
            }

            public function __isset($k): bool
            {
                return array_key_exists($k, $this->d);
            }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect()]);
        $this->withoutMiddleware();
        $this->actingAs($owner)->get(route('stores.rotaplus.index').'?store_id='.$r->id)->assertOk()->assertSeeHtml(___('Open shifts'));
        $this->artisan('shifts:remind', ['--hours' => 4])->assertExitCode(0);
    }
}
