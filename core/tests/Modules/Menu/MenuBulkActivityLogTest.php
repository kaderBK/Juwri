<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Models\ActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\TestCase;

/**
 * D6 — a bulk menu action writes exactly one activity-log row (audit trail).
 */
class MenuBulkActivityLogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
    }

    private function storeWithItems(User $owner): array
    {
        $t = 'R'.Str::random(6);
        $store = Store::create(['user_id' => $owner->id, 'slug' => Str::slug($t).'-'.Str::lower(Str::random(5)), 'color' => '#111', 'title' => $t, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1']);
        $ids = [];
        foreach (range(1, 3) as $i) {
            $ids[] = Menu::create(['store_id' => $store->id, 'name' => "I$i", 'price' => 5, 'active' => 1])->id;
        }

        return [$store, $ids];
    }

    public function test_bulk_action_writes_one_scoped_activity_row(): void
    {
        $owner = User::factory()->create();
        [$store, $ids] = $this->storeWithItems($owner);

        $this->actingAs($owner)->post(route('stores.menuBulk', $store->id), [
            'action' => 'deactivate', 'ids' => $ids,
        ])->assertOk();

        $rows = ActivityLog::where('action', 'menu.bulk.deactivate')->where('store_id', $store->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame(3, (int) $rows->first()->meta['count']);
        $this->assertEqualsCanonicalizing($ids, $rows->first()->meta['ids']);
    }

    public function test_bulk_price_set_logs_the_new_price(): void
    {
        $owner = User::factory()->create();
        [$store, $ids] = $this->storeWithItems($owner);

        $this->actingAs($owner)->post(route('stores.menuBulk', $store->id), [
            'action' => 'price_set', 'price' => 7.5, 'ids' => $ids,
        ])->assertOk();

        $row = ActivityLog::where('action', 'menu.bulk.price_set')->first();
        $this->assertNotNull($row);
        $this->assertEquals(7.5, (float) $row->meta['price']);
    }
}
