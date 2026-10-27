<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Inventory;

use App\Services\Storefront\StorefrontMenuCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\TestCase;

/**
 * WI-H5: Menu::deductStock / restoreStock must be ONE atomic conditional UPDATE.
 * The old `lockForUpdate()->find()` + read-modify-write was a no-op under
 * autocommit (InnoDB drops the row lock at statement end and no call site opens a
 * transaction), so two concurrent orders both read the same stock and one
 * deduction was silently lost. sqlite serialises writes, so these tests prove the
 * SHAPE of the statements; the true concurrency proof is the MySQL script in §6.
 */
class StockDeductionAtomicTest extends TestCase
{
    use RefreshDatabase;

    private function storeFixture(): Store
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
        ]);
    }

    private function item(Store $r, int $qty, bool $soldOut = false): Menu
    {
        return Menu::create([
            'store_id' => $r->id, 'name' => 'Burger', 'price' => 9, 'active' => 1,
            'track_inventory' => 1, 'stock_qty' => $qty, 'is_sold_out' => $soldOut ? 1 : 0,
        ]);
    }

    public function test_a_write_landing_between_read_and_write_is_not_lost(): void
    {
        $item = $this->item($this->storeFixture(), 10);
        $id = $item->id;

        $fired = false;
        DB::listen(function ($q) use (&$fired, $id) {
            if (! $fired && preg_match('/[`"]items[`"]/', $q->sql) === 1) {
                $fired = true;
                // A competing order deducts 5 the instant we touch the row.
                Menu::whereKey($id)->decrement('stock_qty', 5);
            }
        });

        Menu::deductStock($id, 3);

        // Post-fix: the first menu statement IS the conditional decrement, the
        // competing -5 applies afterwards -> 10 - 3 - 5 = 2. Pre-fix the absolute
        // write of 7 clobbers the competing -5 -> 7.
        $this->assertSame(2, (int) Menu::find($id)->stock_qty);
    }

    public function test_deduct_reports_failure_and_flags_sold_out_when_stock_is_short(): void
    {
        $r = $this->storeFixture();
        $item = $this->item($r, 3);

        $ok = Menu::deductStock($item->id, 5);

        $this->assertFalse($ok);
        // Oversell goes NEGATIVE (truthful), not clamped to 0 — the full qty is still
        // deducted so deduct/restore stay symmetric and a later void can't mint phantom
        // units (clamp-to-0 then restore +5 would). See Menu::deductStock.
        $this->assertSame(-2, (int) $item->fresh()->stock_qty); // 3 - 5
        $this->assertTrue((bool) $item->fresh()->is_sold_out);
        $this->assertSame(1, (int) (store_options($r->id, 'stock_oversell_count', 0) ?: 0));
    }

    public function test_deduct_issues_no_select_for_update(): void
    {
        $item = $this->item($this->storeFixture(), 10);

        $sql = [];
        DB::listen(function ($q) use (&$sql) {
            $sql[] = $q->sql;
        });

        Menu::deductStock($item->id, 1);

        foreach ($sql as $s) {
            $this->assertStringNotContainsStringIgnoringCase('for update', $s);
        }
        $decrements = array_filter($sql, fn ($s) => preg_match('/update .*items.* set .*stock_qty.* = .*stock_qty.* - /i', $s) && preg_match('/stock_qty.{0,3}>=/i', $s));
        $this->assertNotEmpty($decrements, 'expected one conditional decrement with a stock_qty >= guard');
    }

    public function test_restore_is_a_single_increment_and_clears_sold_out(): void
    {
        $item = $this->item($this->storeFixture(), 0, soldOut: true);

        $sql = [];
        DB::listen(function ($q) use (&$sql) {
            $sql[] = $q->sql;
        });

        Menu::restoreStock($item->id, 4);

        $this->assertSame(4, (int) $item->fresh()->stock_qty);
        $this->assertFalse((bool) $item->fresh()->is_sold_out);
        foreach ($sql as $s) {
            $this->assertStringNotContainsStringIgnoringCase('for update', $s);
        }
    }

    public function test_sold_out_crossing_still_fires_a_model_update(): void
    {
        $item = $this->item($this->storeFixture(), 1);

        $changed = 0;
        Event::listen('eloquent.updated: '.Menu::class, function ($menu) use (&$changed) {
            if ($menu instanceof Menu && $menu->wasChanged('is_sold_out')) {
                $changed++;
            }
        });

        Menu::deductStock($item->id, 1);

        $this->assertSame(1, $changed, 'crossing to sold-out must fire one model update for the aggregator sync');
        $this->assertTrue((bool) $item->fresh()->is_sold_out);
    }

    public function test_deduction_busts_the_storefront_menu_cache(): void
    {
        $r = $this->storeFixture();
        $item = $this->item($r, 10);

        $before = StorefrontMenuCache::version($r->id);
        Menu::deductStock($item->id, 1);
        $after = StorefrontMenuCache::version($r->id);

        // M2: the menu-cache version is a random token now, replaced (not incremented) on a bust.
        $this->assertNotSame($before, $after);
    }
}
