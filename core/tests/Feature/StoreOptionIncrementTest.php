<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Tests\TestCase;

/**
 * StoreOption::incrementStoreOption() — the atomic accumulator behind paySuccess's
 * wallet_amount. The old read-modify-write lost a concurrent update; each credit
 * must add to the PERSISTED value, never overwrite a stale read.
 */
class StoreOptionIncrementTest extends TestCase
{
    use RefreshDatabase;

    private function walletOf(int $storeId): float
    {
        return (float) StoreSettings::legacy((int) $storeId, 'wallet_amount');
    }

    /** store_options.store_id is a FK to posts, so create a real restaurant. */
    private function storeId(): int
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1',
        ])->id;
    }

    public function test_increment_accumulates_and_never_overwrites(): void
    {
        $pid = $this->storeId();

        StoreOption::incrementStoreOption($pid, 'wallet_amount', 10);
        StoreOption::incrementStoreOption($pid, 'wallet_amount', 10);
        StoreOption::incrementStoreOption($pid, 'wallet_amount', 5.25);

        // A read-modify-write from a stale read would land 5.25 (or 10); an atomic
        // accumulator lands the sum.
        $this->assertEqualsWithDelta(25.25, $this->walletOf($pid), 0.0001);
    }

    public function test_increment_creates_the_row_when_absent(): void
    {
        $pid = $this->storeId();
        $this->assertSame(0.0, $this->walletOf($pid));

        StoreOption::incrementStoreOption($pid, 'wallet_amount', 7.5);

        $this->assertEqualsWithDelta(7.5, $this->walletOf($pid), 0.0001);
    }
}
