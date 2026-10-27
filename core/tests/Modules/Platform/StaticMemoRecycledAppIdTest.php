<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Models\Order;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Services\Tenancy\TenantFill;
use ReflectionProperty;
use Tests\Support\StaticMemoStamps;
use Tests\TestCase;

/**
 * A recycled application object id (PHP reuses ids of collected objects) makes a static memo's
 * `spl_object_id(app())` stamp match in the NEXT test, so a stale store => account entry survived and tagged the
 * new store's orders with a foreign account: DashNotifPrefsCountsTest counted 0 instead of 2, only in some
 * full-suite orders. TestCase::setUp() nulls every stamp; this proves the reset drops a memo whose stamp equals
 * the current app id.
 */
class StaticMemoRecycledAppIdTest extends TestCase
{
    use RefreshDatabase;

    private function poisonTenantFill(int $storeId): void
    {
        $memo = new ReflectionProperty(TenantFill::class, 'storeAccounts');
        $memo->setValue(null, [$storeId => 987654]);
        (new ReflectionProperty(TenantFill::class, 'app'))->setValue(null, spl_object_id(app())); // the "recycled id"
    }

    public function test_a_memo_stamped_with_a_recycled_app_id_is_dropped_by_the_reset(): void
    {
        $this->poisonTenantFill(1);
        StaticMemoStamps::reset();

        $owner = User::factory()->create();
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'recycled-'.strtolower(bin2hex(random_bytes(3))),
            'color' => '#111', 'title' => 'R', 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1', 'phone' => '1',
        ]);
        $order = (new Order)->forceFill([
            'store_id' => $store->id, 'type' => 'takeaway', 'status' => 'new', 'is_paid' => 0, 'source' => 'qr',
        ]);
        $order->save();

        $this->assertNotSame(987654, $order->fresh()->account_id, 'stale store=>account memo leaked from a previous app');
    }
}
