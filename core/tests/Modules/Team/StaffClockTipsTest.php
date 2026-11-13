<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Team;

use App\Http\Middleware\Installed;
use App\Models\Order;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\TestCase;

class StaffClockTipsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(Installed::class);
    }

    private function ctx(): array
    {
        $o = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $p = Store::create(['user_id' => $o->id, 'slug' => Str::slug('r'.Str::random(6)), 'color' => '#111', 'title' => 'D', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now()]);
        $s = StaffMember::create(['owner_id' => $o->id, 'store_id' => $p->id, 'name' => 'S', 'role' => 'manager', 'email' => 's'.Str::random(5).'@ex.com', 'password' => Hash::make('x'), 'active' => 1]);

        return [$p, $s];
    }

    public function test_clock_in_out_single_open_shift(): void
    {
        [$p,$s] = $this->ctx();
        $this->actingAs($s, 'staff')->postJson(route('staffapp.clockIn'), ['method' => 'pin'])->assertStatus(201);
        $this->actingAs($s, 'staff')->postJson(route('staffapp.clockIn'))->assertStatus(422); // already in
        $this->actingAs($s, 'staff')->postJson(route('staffapp.clockOut'))->assertOk()->assertJsonStructure(['shift_id']);
        $this->actingAs($s, 'staff')->postJson(route('staffapp.clockOut'))->assertStatus(422); // none open
    }

    public function test_tips_scoped_to_served_orders(): void
    {
        [$p,$s] = $this->ctx();
        Order::create(['store_id' => $p->id, 'type' => 'takeaway', 'status' => 'completed', 'served_by_staff_id' => $s->id, 'tip_amount' => 5, 'created_at' => now()]);
        Order::create(['store_id' => $p->id, 'type' => 'takeaway', 'status' => 'completed', 'served_by_staff_id' => $s->id, 'tip_amount' => 3, 'created_at' => now()]);
        Order::create(['store_id' => $p->id, 'type' => 'takeaway', 'status' => 'completed', 'tip_amount' => 99, 'created_at' => now()]); // not served by staff
        $res = $this->actingAs($s, 'staff')->getJson(route('staffapp.tips'))->assertOk();
        $this->assertSame(2, $res->json('orders'));
        $this->assertEquals(8.0, $res->json('tips'));
    }
}
