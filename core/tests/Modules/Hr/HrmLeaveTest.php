<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hr;

use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Hr\Models\LeaveType;
use Juwri\Modules\Hr\Models\StaffShift;
use Juwri\Modules\Hr\Services\Hrm\LeaveService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * SYSTEMS-RUN H-P2: leave requests, balances, approvals, rota conflicts.
 */
class HrmLeaveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        config(['settings.admin_allow_hrm_suite' => true]);
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
    }

    private function ownerStaff(): array
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
        $staff = StaffMember::create([
            'owner_id' => $owner->id, 'store_id' => $r->id, 'name' => 'Sam', 'email' => 's'.Str::random(5).'@x.co',
            'password' => Hash::make('secret1'), 'role' => 'waiter', 'active' => true,
        ]);
        LeaveType::ensureSeeded($r->id);

        return [$owner, $r, $staff];
    }

    public function test_index_renders(): void
    {
        [$owner, $r, $staff] = $this->ownerStaff();
        $this->withoutMiddleware();
        $this->actingAs($owner)->get(route('stores.leave.index').'?store_id='.$r->id)->assertOk()->assertSeeHtml(___('Pending approvals'));
    }

    public function test_balance_and_approval_flow(): void
    {
        [$owner, $r, $staff] = $this->ownerStaff();
        $annual = LeaveType::where('store_id', $r->id)->where('name', 'Annual')->first();
        $svc = new LeaveService;

        $req = $svc->request($r->id, $staff->id, [
            'leave_type_id' => $annual->id, 'from_date' => now()->toDateString(), 'to_date' => now()->addDays(2)->toDateString(),
        ]);
        $this->assertSame('pending', $req->status);
        $this->assertEqualsWithDelta(3.0, $req->days(), 0.01);

        // Balance unchanged while pending.
        $this->assertEqualsWithDelta(21.0, $svc->balance($staff->id, $annual), 0.01);
        $svc->approve($req, $owner->id);
        $this->assertEqualsWithDelta(18.0, $svc->balance($staff->id, $annual), 0.01);

        // Can't re-decide an approved request.
        $this->expectException(ValidationException::class);
        $svc->reject($req->fresh(), $owner->id);
    }

    public function test_shift_conflict_detected(): void
    {
        [$owner, $r, $staff] = $this->ownerStaff();
        $annual = LeaveType::where('store_id', $r->id)->where('name', 'Annual')->first();
        StaffShift::create([
            'store_id' => $r->id, 'staff_id' => $staff->id,
            'starts_at' => now()->addDay()->setTime(9, 0), 'ends_at' => now()->addDay()->setTime(17, 0),
        ]);
        $svc = new LeaveService;
        $req = $svc->request($r->id, $staff->id, [
            'leave_type_id' => $annual->id, 'from_date' => now()->toDateString(), 'to_date' => now()->addDays(3)->toDateString(),
        ]);
        $this->assertSame(1, $svc->shiftConflicts($req));
    }

    public function test_approve_endpoint_and_tenant_scope(): void
    {
        [$owner, $r, $staff] = $this->ownerStaff();
        [$other, $otherR, $otherStaff] = $this->ownerStaff();
        $annual = LeaveType::where('store_id', $otherR->id)->where('name', 'Annual')->first();
        $foreign = new LeaveService()->request($otherR->id, $otherStaff->id, [
            'leave_type_id' => $annual->id, 'from_date' => now()->toDateString(), 'to_date' => now()->toDateString(),
        ]);
        $this->withoutMiddleware();
        $this->actingAs($owner)->post(route('stores.leave.approve', $foreign->id).'?store_id='.$r->id)
            ->assertNotFound();
    }
}
