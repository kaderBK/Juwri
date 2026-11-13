<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StaffMember;
use App\Services\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Pos\Models\PosTerminal;
use Juwri\Modules\Pos\Models\PrinterProfile;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * D8 — terminal registry: the print-profile resolve order (terminal → branch →
 * store default), the heartbeat, and retire soft-hiding a device.
 */
class PosTerminalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function storeFixture(array $settings = []): Store
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => $settings + ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function staff(Store $r, string $role = 'manager'): StaffMember
    {
        return StaffMember::create([
            'owner_id' => $r->user_id, 'store_id' => $r->id,
            'name' => 'M '.Str::random(4), 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => $role, 'active' => 1,
        ]);
    }

    private function profile(Store $r, array $attrs = []): PrinterProfile
    {
        return PrinterProfile::create(array_merge([
            'store_id' => $r->id, 'name' => 'P'.Str::random(3), 'transport' => 'webserial',
            'width_mm' => 80, 'events' => ['new_order'], 'station_ids' => [],
            'auto_print' => true, 'active' => true, 'copies' => 1,
        ], $attrs));
    }

    public function test_resolve_order_terminal_then_branch_then_store(): void
    {
        $r = $this->storeFixture();
        $branch = Branch::create(['store_id' => $r->id, 'name' => 'North', 'active' => 1, 'position' => 1]);
        $store = $this->profile($r, ['name' => 'Store', 'branch_id' => null]);
        $branchP = $this->profile($r, ['name' => 'Branch', 'branch_id' => $branch->id]);
        $pinned = $this->profile($r, ['name' => 'Pinned', 'branch_id' => null]);

        // Store-wide only (no branch context) → the store default.
        $this->assertSame($store->id, PrinterProfile::resolveForContext($r->id, null)?->id);

        // A branch-bound profile beats the store-wide one for that branch.
        $this->assertSame($branchP->id, PrinterProfile::resolveForContext($r->id, $branch->id)?->id);

        // A terminal override beats the branch profile.
        $terminal = PosTerminal::create(['store_id' => $r->id, 'branch_id' => $branch->id, 'name' => 'T1', 'printer_profile_id' => $pinned->id]);
        $this->assertSame($pinned->id, PrinterProfile::resolveForContext($r->id, $branch->id, $terminal)?->id);

        // A terminal whose pinned profile is disabled falls back to the branch.
        $pinned->update(['active' => false]);
        $this->assertSame($branchP->id, PrinterProfile::resolveForContext($r->id, $branch->id, $terminal->fresh())?->id);
    }

    public function test_create_and_heartbeat_updates_last_seen(): void
    {
        $r = $this->storeFixture();
        $s = $this->staff($r);

        $create = $this->actingAs($s, 'staff')
            ->postJson(route('pos.terminals.store', ['store' => $r->id]), ['name' => 'Front till'])
            ->assertStatus(201);
        $id = $create->json('id');
        $terminal = PosTerminal::findOrFail($id);
        $seen = $terminal->last_seen_at;

        $this->travel(3)->minutes();
        $this->actingAs($s, 'staff')
            ->postJson(route('pos.terminals.heartbeat', ['store' => $r->id, 'id' => $id]))
            ->assertStatus(200);
        $this->travelBack();

        $this->assertTrue($terminal->fresh()->last_seen_at->gt($seen));
    }

    public function test_the_plan_pos_device_count_is_enforced_and_a_retired_device_frees_its_place(): void
    {
        // Pricing build (PRICING.md §3): Starter = 1 POS device.
        $r = $this->storeFixture(['pos_terminal_limit' => '1']);
        $s = $this->staff($r);

        // The device count is a plan key: an edition that grants every plan key has nothing to withhold.
        if ((string) (Entitlements::planTrace($r->user, 'pos_terminal_limit')['value'] ?? '') !== '1') {
            $this->markTestSkipped('This edition grants every plan key (Community): nothing to withhold.');
        }

        $first = $this->actingAs($s, 'staff')
            ->postJson(route('pos.terminals.store', ['store' => $r->id]), ['name' => 'Front till'])->assertCreated();
        $this->actingAs($s, 'staff')
            ->postJson(route('pos.terminals.store', ['store' => $r->id]), ['name' => 'Second till'])
            ->assertForbidden()->assertJson(['error' => 'pos_terminal_limit', 'limit' => 1]);
        $this->assertSame(1, PosTerminal::where('store_id', $r->id)->count());

        $this->actingAs($s, 'staff')
            ->postJson(route('pos.terminals.retire', ['store' => $r->id, 'id' => $first->json('id')]))->assertOk();
        $this->actingAs($s, 'staff')
            ->postJson(route('pos.terminals.store', ['store' => $r->id]), ['name' => 'Replacement'])->assertCreated();
    }

    public function test_a_plan_without_a_pos_device_count_stays_unlimited(): void
    {
        $r = $this->storeFixture();
        $s = $this->staff($r);
        foreach (range(1, 3) as $i) {
            $this->actingAs($s, 'staff')
                ->postJson(route('pos.terminals.store', ['store' => $r->id]), ['name' => 'Till '.$i])->assertCreated();
        }
    }

    public function test_retire_hides_the_terminal_from_the_list(): void
    {
        $r = $this->storeFixture();
        $s = $this->staff($r);
        $terminal = PosTerminal::create(['store_id' => $r->id, 'name' => 'Old', 'last_seen_at' => now()]);

        $this->actingAs($s, 'staff')
            ->postJson(route('pos.terminals.retire', ['store' => $r->id, 'id' => $terminal->id]))
            ->assertStatus(200);

        $this->assertNotNull($terminal->fresh()->retired_at);
        $list = $this->actingAs($s, 'staff')
            ->getJson(route('pos.terminals', ['store' => $r->id]))->assertOk();
        $this->assertCount(0, $list->json('terminals'));
    }

    public function test_update_pins_only_an_own_profile(): void
    {
        $r = $this->storeFixture();
        $s = $this->staff($r);
        $terminal = PosTerminal::create(['store_id' => $r->id, 'name' => 'T']);
        $mine = $this->profile($r, ['name' => 'Mine']);

        // Pin a valid profile.
        $this->actingAs($s, 'staff')
            ->postJson(route('pos.terminals.update', ['store' => $r->id, 'id' => $terminal->id]), ['printer_profile_id' => $mine->id])
            ->assertStatus(200)->assertJsonPath('printer_profile_id', $mine->id);

        // A foreign / bogus profile id is dropped to null.
        $this->actingAs($s, 'staff')
            ->postJson(route('pos.terminals.update', ['store' => $r->id, 'id' => $terminal->id]), ['printer_profile_id' => 999999])
            ->assertStatus(200)->assertJsonPath('printer_profile_id', null);
    }

    public function test_retire_requires_manager(): void
    {
        $r = $this->storeFixture();
        $cashier = $this->staff($r, 'cashier'); // no pos_shift_close
        $terminal = PosTerminal::create(['store_id' => $r->id, 'name' => 'T']);

        $this->actingAs($cashier, 'staff')
            ->postJson(route('pos.terminals.retire', ['store' => $r->id, 'id' => $terminal->id]))
            ->assertStatus(403);
        $this->assertNull($terminal->fresh()->retired_at);
    }
}
