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
 * Floor-Map FP2 — first-class table status: set lifecycle status, column is source of
 * truth in the status feed, branch-scoped, foreign table rejected.
 */
class FloorTableStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function setup2(): array
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $branch = Branch::where('store_id', $r->id)->first();
        $table = StoreTable::create([
            'store_id' => $r->id, 'branch_id' => $branch->id, 'table_no' => 1,
            'key' => Str::random(8), 'map_x' => 50, 'map_y' => 50, 'shape' => 'square', 'seats' => 4,
        ]);

        return [$owner, $r, $branch, $table];
    }

    public function test_set_status_updates_table_and_stamps_time(): void
    {
        [$owner, $r, $branch, $table] = $this->setup2();

        $this->actingAs($owner)->postJson(route('floor-map.setStatus', [$r->id, $table->id]).'?branch='.$branch->id, [
            'status' => 'seated', 'party_size' => 3,
        ])->assertOk()->assertJsonPath('status', 'seated');

        $table->refresh();
        $this->assertSame('seated', $table->status);
        $this->assertSame(3, (int) $table->party_size);
        $this->assertNotNull($table->status_changed_at);
    }

    public function test_status_feed_prefers_table_status_column(): void
    {
        [$owner, $r, $branch, $table] = $this->setup2();
        $table->update(['status' => 'dirty', 'status_changed_at' => now()]);

        $res = $this->actingAs($owner)->getJson(route('floor-map.status', $r->id).'?branch='.$branch->id)->assertOk();
        $this->assertSame('dirty', $res->json()[$table->id]['status']);
        $this->assertArrayHasKey('seconds_in_status', $res->json()[$table->id]);
    }

    public function test_foreign_table_status_rejected(): void
    {
        [$owner, $r, $branch, $table] = $this->setup2();
        [$owner2, $r2, $branch2, $foreignTable] = $this->setup2();

        $this->actingAs($owner)->postJson(route('floor-map.setStatus', [$r->id, $foreignTable->id]).'?branch='.$branch->id, [
            'status' => 'seated',
        ])->assertStatus(404);
    }
}
