<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Team;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\SyncDashboardStore;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\TestCase;

/**
 * S5 — hash staff PINs. New PINs persist as bcrypt pin_hash (no plaintext);
 * PIN auth resolves via Hash::check with a legacy-plaintext fallback; the
 * staff:hash-legacy-pins backfill fills pin_hash and nulls the plaintext.
 */
class StaffPinHashTest extends TestCase
{
    use RefreshDatabase;

    private function storeFixture(): Store
    {
        $owner = User::create([
            'name' => 'Owner '.Str::random(4),
            'email' => Str::lower(Str::random(8)).'@test.dev',
            'password' => bcrypt('secret'),
        ]);

        return Store::create([
            'user_id' => $owner->id,
            'slug' => 'r-'.Str::lower(Str::random(6)),
            'color' => '#111', 'title' => 'R', 'sub_title' => 'x',
            'description' => 'x', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St',
            'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_authenticate_matches_hashed_row(): void
    {
        $r = $this->storeFixture();
        StaffMember::create([
            'owner_id' => $r->user_id, 'store_id' => $r->id, 'name' => 'H',
            'email' => Str::lower(Str::random(6)).'@t.dev', 'password' => bcrypt('x'),
            'pin_hash' => Hash::make('4321'), 'role' => 'cashier', 'active' => 1,
        ]);

        $this->assertNotNull(StaffMember::authenticateByPin($r->id, '4321'));
        $this->assertNull(StaffMember::authenticateByPin($r->id, '0000'));
    }

    public function test_authenticate_falls_back_to_legacy_plaintext_row(): void
    {
        $r = $this->storeFixture();
        StaffMember::create([
            'owner_id' => $r->user_id, 'store_id' => $r->id, 'name' => 'L',
            'email' => Str::lower(Str::random(6)).'@t.dev', 'password' => bcrypt('x'),
            'pin' => '9911', 'role' => 'cashier', 'active' => 1,
        ]);

        $match = StaffMember::authenticateByPin($r->id, '9911');
        $this->assertNotNull($match);
        $this->assertNull(StaffMember::authenticateByPin($r->id, '1111'));
        // P6-88: the first good login rehashes the row and drops the plaintext.
        $match->refresh();
        $this->assertNull($match->pin);
        $this->assertTrue(Hash::check('9911', (string) $match->pin_hash));
    }

    public function test_write_path_hashes_and_omits_plaintext(): void
    {
        $r = $this->storeFixture();
        $owner = User::find($r->user_id);

        $this->actingAs($owner)
            ->withoutMiddleware([
                Installed::class,
                SyncDashboardStore::class,
                EnsureStaffDashboardPermission::class,
            ])
            ->post(route('staff.store'), [
                'name' => 'New', 'email' => Str::lower(Str::random(6)).'@t.dev',
                'password' => 'secret1', 'pin' => '5566', 'role' => 'cashier',
                'store_id' => $r->id,
            ]);

        $staff = StaffMember::where('store_id', $r->id)->where('name', 'New')->first();
        $this->assertNotNull($staff);
        $this->assertNull($staff->pin, 'plaintext pin must not be written by default');
        $this->assertNotEmpty($staff->pin_hash);
        $this->assertTrue(Hash::check('5566', $staff->pin_hash));
        $this->assertNotNull(StaffMember::authenticateByPin($r->id, '5566'));
    }

    public function test_backfill_command_hashes_legacy_rows(): void
    {
        $r = $this->storeFixture();
        $s = StaffMember::create([
            'owner_id' => $r->user_id, 'store_id' => $r->id, 'name' => 'B',
            'email' => Str::lower(Str::random(6)).'@t.dev', 'password' => bcrypt('x'),
            'pin' => '7788', 'role' => 'cashier', 'active' => 1,
        ]);

        $this->assertNull($s->pin_hash);

        $this->artisan('staff:hash-legacy-pins')->assertExitCode(0);

        $s->refresh();
        $this->assertNotEmpty($s->pin_hash);
        $this->assertTrue(Hash::check('7788', $s->pin_hash));
        $this->assertNull($s->pin, 'P6-88: plaintext nulled by the backfill');
        $this->assertNotNull(StaffMember::authenticateByPin($r->id, '7788'));
    }
}
