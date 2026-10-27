<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\TestCase;

/**
 * D9 — orders CSV export: BOM-prefixed, ownership-scoped, filter-aware, behind
 * ratelimit:export.
 */
class OrdersExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();
    }

    private function store(User $owner): Store
    {
        $t = 'R'.Str::random(6);

        return Store::create(['user_id' => $owner->id, 'slug' => Str::slug($t).'-'.Str::lower(Str::random(5)), 'color' => '#111', 'title' => $t, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1']);
    }

    public function test_export_streams_a_bom_prefixed_csv_of_orders(): void
    {
        $owner = User::factory()->create();
        $store = $this->store($owner);
        Order::create(['store_id' => $store->id, 'status' => 'completed', 'type' => 'delivery', 'customer_name' => 'Jane', 'phone_number' => '555', 'is_paid' => 1]);

        $res = $this->actingAs($owner)->get(route('stores.ordersExportCsv', $store->id));
        $res->assertOk();
        $res->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $body = $res->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body); // UTF-8 BOM
        $this->assertStringContainsString('customer_name', $body);
        $this->assertStringContainsString('Jane', $body);
    }

    public function test_export_honours_status_filter(): void
    {
        $owner = User::factory()->create();
        $store = $this->store($owner);
        Order::create(['store_id' => $store->id, 'status' => 'completed', 'type' => 'delivery', 'customer_name' => 'Done', 'phone_number' => '1', 'is_paid' => 1]);
        Order::create(['store_id' => $store->id, 'status' => 'pending', 'type' => 'delivery', 'customer_name' => 'Wait', 'phone_number' => '2', 'is_paid' => 0]);

        $body = $this->actingAs($owner)->get(route('stores.ordersExportCsv', [$store->id, 'status' => 'completed']))->streamedContent();
        $this->assertStringContainsString('Done', $body);
        $this->assertStringNotContainsString('Wait', $body);
    }

    public function test_export_is_ownership_scoped(): void
    {
        $owner = User::factory()->create();
        $store = $this->store($owner);
        $attacker = User::factory()->create();

        $this->actingAs($attacker)->get(route('stores.ordersExportCsv', $store->id))->assertNotFound();
    }

    public function test_route_is_behind_ratelimit_export(): void
    {
        $mw = collect(Route::getRoutes()->getByName('stores.ordersExportCsv')->gatherMiddleware());
        $this->assertTrue($mw->contains('ratelimit:export'));
    }
}
