<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Inventory;

use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Tests\Support\CommittedStoreFixtures;
use Tests\TestCase;

/**
 * P4-04 done-definition: "two orders, one last unit" on a real server database (MySQL and PostgreSQL; skipped on sqlite).
 * Separate PHP processes released at the same instant take stock the way the storefront order does (Menu::reserveStock,
 * one conditional UPDATE); exactly as many as the shelf holds win and the stock never goes below zero.
 *
 * Fixture rows are COMMITTED (the other processes must see them) and deleted in tearDown.
 */
class StockReserveRaceTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<int> */
    private array $stores = [];

    /** @return array<int, string|null> */
    protected function connectionsToTransact(): array
    {
        return $this->usingInMemoryDatabase() ? [config('database.default')] : [];
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'pgsql'], true)) {
            $this->markTestSkipped('Cross-process race: runs on MySQL and PostgreSQL (StorefrontStockGuardTest covers the rules on sqlite).');
        }
        app()->setLocale('en');
    }

    protected function tearDown(): void
    {
        $stores = $this->stores;
        $this->stores = [];
        if ($stores !== [] && in_array(DB::connection()->getDriverName(), ['mysql', 'pgsql'], true)) {
            // Every committed row of these stores goes, including the default branch Store::create makes (left behind, it
            // broke later tests in the same process that need an empty table, e.g. the legacy importer's guard).
            DB::table('menu_variants')->whereIn('menu_id', DB::table('items')->whereIn('store_id', $stores)->pluck('id')->all())->delete();
            CommittedStoreFixtures::purge($stores);
        }
        parent::tearDown();
    }

    private function item(int $stock): Menu
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)), 'color' => '#111', 'title' => $title,
            'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
        ]);
        $this->stores[] = (int) $store->id;
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat']);

        return Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Last one', 'price' => 10, 'active' => 1,
            'track_inventory' => 1, 'stock_qty' => $stock, 'low_stock_threshold' => 0,
        ]);
    }

    /** @return list<string> the children's answers, sorted */
    private function race(int $n, Menu $item, int $units, ?MenuVariant $variant = null): array
    {
        $conn = config('database.connections.'.config('database.default'));
        $env = array_merge(getenv(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => (string) config('database.default'),
            'DB_HOST' => (string) ($conn['host'] ?? '127.0.0.1'),
            'DB_PORT' => (string) ($conn['port'] ?? ''),
            'DB_DATABASE' => (string) $conn['database'],
            'DB_USERNAME' => (string) ($conn['username'] ?? ''),
            'DB_PASSWORD' => (string) ($conn['password'] ?? ''),
            'DB_PREFIX' => (string) ($conn['prefix'] ?? ''),
        ]);
        $go = sprintf('%.6F', microtime(true) + 4.0);
        $procs = [];
        for ($i = 0; $i < $n; $i++) {
            $cmd = [PHP_BINARY, __DIR__.'/Support/stock_race_child.php', (string) $item->id, (string) $units, $go, (string) ($variant->id ?? 0)];
            $pipes = [];
            $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);
            $this->assertIsResource($proc);
            $procs[] = [$proc, $pipes];
        }
        $out = [];
        foreach ($procs as [$proc, $pipes]) {
            $stdout = trim((string) stream_get_contents($pipes[1]));
            $stderr = trim((string) stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
            $out[] = $stdout !== '' ? $stdout : 'error: '.substr($stderr, 0, 500);
        }
        sort($out);

        return $out;
    }

    public function test_two_orders_racing_for_the_last_unit_exactly_one_wins(): void
    {
        $item = $this->item(1);

        $this->assertSame(['ok', 'short'], $this->race(2, $item, 1));
        $this->assertSame(0, (int) $item->fresh()->stock_qty, 'the stock is exactly spent, never negative');
        $this->assertTrue((bool) $item->fresh()->is_sold_out);
    }

    public function test_four_orders_for_two_units_exactly_two_win(): void
    {
        $item = $this->item(2);

        $this->assertSame(['ok', 'ok', 'short', 'short'], $this->race(4, $item, 1));
        $this->assertSame(0, (int) $item->fresh()->stock_qty);
    }

    public function test_an_order_for_more_than_is_left_never_takes_a_partial_amount(): void
    {
        $item = $this->item(3);

        $this->assertSame(['ok', 'short'], $this->race(2, $item, 2));
        $this->assertSame(1, (int) $item->fresh()->stock_qty, 'one winner took 2; the loser took nothing');
    }

    /** P6-103: the storefront line takes item + variant cell together; the last size goes to exactly one shopper. */
    public function test_two_shoppers_racing_for_the_last_variant_unit_exactly_one_wins_and_the_loser_takes_nothing(): void
    {
        $item = $this->item(5);
        $large = MenuVariant::create(['menu_id' => $item->id, 'store_id' => $item->store_id, 'price' => 10, 'options' => (object) [],
            'stock_qty' => 1, 'active' => true, 'position' => 1]);

        $this->assertSame(['ok', 'short'], $this->race(2, $item, 1, $large));
        $this->assertSame(0, (int) $large->fresh()->stock_qty, 'never negative');
        $this->assertSame(4, (int) $item->fresh()->stock_qty, 'the loser\'s item unit was rolled back with its line');
    }

    /** P6-103: an untracked item is never refused, however many race. */
    public function test_untracked_items_are_unaffected_by_the_race(): void
    {
        $item = $this->item(1);
        Menu::query()->whereKey($item->id)->update(['track_inventory' => 0, 'stock_qty' => null]);

        $this->assertSame(['ok', 'ok', 'ok'], $this->race(3, $item->fresh(), 1));
    }
}
