<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Tenancy\Mut;

use App\Models\Order;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;

/**
 * P6-27: two accounts — owner A (stores A1, A2) and owner B (store B) — with one order per store.
 */
trait TenancyFixtures
{
    protected User $ownerA;

    protected User $ownerB;

    protected Store $storeA1;

    protected Store $storeA2;

    protected Store $storeB;

    protected function seedTenants(): void
    {
        $this->ownerA = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $this->ownerB = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $this->storeA1 = $this->makeStore($this->ownerA);
        $this->storeA2 = $this->makeStore($this->ownerA);
        $this->storeB = $this->makeStore($this->ownerB);
        $this->ownerA->refresh();
        $this->ownerB->refresh();
        foreach ([$this->storeA1, $this->storeA2, $this->storeB] as $store) {
            $store->refresh();
            Order::create(['store_id' => $store->id, 'status' => 'new', 'type' => 'takeaway']);
        }
    }

    protected function makeStore(User $owner): Store
    {
        return Store::create([
            'user_id' => $owner->id, 'title' => 'S', 'slug' => 'mut-'.Str::lower(Str::random(8)),
            'color' => '#fff', 'sub_title' => 's', 'description' => 'd',
            'main_image' => 'x.png', 'cover_image' => 'x.png', 'address' => 'a', 'phone' => '1',
        ]);
    }

    /** @return list<int> store ids of the orders the current (possibly scoped) query sees */
    protected function visibleOrderStores(): array
    {
        return Order::orderBy('id')->pluck('store_id')->map(fn ($v) => (int) $v)->all();
    }
}
