<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Tables;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\TestCase;

/**
 * Floor-Map FP1 — multi-branch foundation: per-branch table scoping + isolation,
 * default-branch fallback, cross-restaurant branch rejection, all-locations overview.
 */
class FloorMapMultiBranchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function storeFixture(User $owner): Store
    {
        $title = 'R '.Str::random(5);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function table(Store $r, Branch $b, int $no): StoreTable
    {
        return StoreTable::create([
            'store_id' => $r->id, 'branch_id' => $b->id, 'table_no' => $no,
            'key' => Str::random(10), 'map_x' => 50, 'map_y' => 50, 'shape' => 'square', 'seats' => 4,
        ]);
    }

    public function test_status_is_scoped_to_the_branch(): void
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $r = $this->storeFixture($owner);
        $main = Branch::where('store_id', $r->id)->first();
        $second = Branch::create(['store_id' => $r->id, 'name' => 'Second', 'active' => true, 'position' => 1]);

        $this->table($r, $main, 1);
        $this->table($r, $second, 2);

        $resMain = $this->actingAs($owner)->getJson(route('floor-map.status', $r->id).'?branch='.$main->id)->assertOk();
        $this->assertCount(1, $resMain->json());

        $resSecond = $this->actingAs($owner)->getJson(route('floor-map.status', $r->id).'?branch='.$second->id)->assertOk();
        $this->assertCount(1, $resSecond->json());

        // the two branches return different tables (isolation)
        $this->assertNotSame(array_keys($resMain->json()), array_keys($resSecond->json()));
    }

    public function test_branch_from_another_restaurant_is_rejected(): void
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $r = $this->storeFixture($owner);
        $otherOwner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $otherR = $this->storeFixture($otherOwner);
        $foreignBranch = Branch::where('store_id', $otherR->id)->first();

        $this->actingAs($owner)->getJson(route('floor-map.status', $r->id).'?branch='.$foreignBranch->id)
            ->assertStatus(404);
    }

    public function test_default_branch_used_when_none_specified(): void
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $r = $this->storeFixture($owner);
        $main = Branch::where('store_id', $r->id)->first();
        $this->table($r, $main, 1);

        $res = $this->actingAs($owner)->getJson(route('floor-map.status', $r->id))->assertOk();
        $this->assertCount(1, $res->json());
    }

    public function test_all_locations_overview_lists_every_branch(): void
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $r = $this->storeFixture($owner);
        $main = Branch::where('store_id', $r->id)->first();
        $second = Branch::create(['store_id' => $r->id, 'name' => 'Second', 'active' => true, 'position' => 1]);
        $this->table($r, $main, 1);
        $this->table($r, $second, 2);
        $this->table($r, $second, 3);

        $res = $this->actingAs($owner)->getJson(route('floor-map.all', $r->id))->assertOk();
        $rows = collect($res->json());
        $this->assertSame(2, $rows->count());
        $this->assertSame(2, (int) $rows->firstWhere('branch_id', $second->id)['tables']);
        $this->assertSame(8, (int) $rows->firstWhere('branch_id', $second->id)['seats']);
    }
}
