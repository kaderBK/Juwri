<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Team;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\TestCase;

/**
 * Security (AUTH-1): a staff session is logged into the web guard AS THE OWNER.
 * Routes that only require `auth` (social connect, two-factor management,
 * partner/reseller portals) used to be reachable by staff as the owner. The
 * StaffSessionFence applies the default-deny staff map to every web-auth route.
 */
class StaffSessionFenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    /** @return array{0: User, 1: StaffMember} */
    private function ownerAndStaff(): array
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
        ]);
        $staff = StaffMember::create([
            'owner_id' => $owner->id, 'store_id' => $store->id, 'name' => 'Waiter',
            'email' => Str::lower(Str::random(8)).'@t.test', 'password' => Hash::make('secret123'),
            'role' => 'waiter', 'active' => 1,
        ]);

        return [$owner, $staff];
    }

    private function asStaff(User $owner, StaffMember $staff): self
    {
        return $this->actingAs($owner)->actingAs($staff, 'staff')->withSession(['staff_session_id' => $staff->id]);
    }

    public function test_staff_cannot_reach_owner_account_security_or_portals(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        [$owner, $staff] = $this->ownerAndStaff();

        $this->asStaff($owner, $staff)->get(route('social.connect', 'google'))->assertForbidden();
        $this->asStaff($owner, $staff)->post(route('2fa.enable'))->assertForbidden();
        $this->asStaff($owner, $staff)->get(route('2fa.recovery'))->assertForbidden();
        $this->asStaff($owner, $staff)->get(route('partner.overview'))->assertForbidden();

        $this->assertNull($owner->fresh()->two_factor_secret ?? null);
    }

    public function test_owner_without_a_staff_session_is_not_fenced(): void
    {
        [$owner] = $this->ownerAndStaff();

        $this->assertNotSame(403, $this->actingAs($owner)->get(route('2fa.show'))->status());
    }

    public function test_staff_still_reach_routes_their_map_allows(): void
    {
        [$owner, $staff] = $this->ownerAndStaff();

        // 'dashboard' is mapped to null (allowed for every staff role).
        $this->assertNotSame(403, $this->asStaff($owner, $staff)->get(route('dashboard'))->status());
    }
}
