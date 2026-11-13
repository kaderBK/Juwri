<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\TestCase;

/**
 * The barcoder cutover surfaced two placement defects the old
 * restaurant-keyed storefront rendering never exposed: items whose category
 * belongs to a different store (cross-tenant) and live items whose category
 * is gone (orphans — invisible to category-driven rendering, so also
 * uneditable in the merchant's menu editor). Pins the menu:audit-placement
 * report and the --fix policy: duplicate orphans are soft-deleted, the rest
 * re-home into a HIDDEN (inactive) "Uncategorized" bucket so they never flash
 * onto a customer menu.
 */
class MenuPlacementAuditTest extends TestCase
{
    use RefreshDatabase {
        beginDatabaseTransaction as refreshDatabaseBeginTransaction;
    }

    private int $storeA;

    private int $storeB;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->storeA, $this->storeB] = [
            $this->store('store-a'),
            $this->store('store-b'),
        ];

        MenuCategory::query()->create([
            'store_id' => $this->storeA, 'name' => 'Drinks', 'parent' => null, 'position' => 1, 'active' => '1',
        ]);
        MenuCategory::query()->create([
            'store_id' => $this->storeB, 'name' => 'Lunch', 'parent' => null, 'position' => 1, 'active' => '1',
        ]);
    }

    /**
     * FK relaxation must land AFTER the connection exists but BEFORE
     * RefreshDatabase opens its wrapper transaction (a PRAGMA inside a
     * transaction is a silent no-op). This runs exactly there, per test, so it
     * holds under --parallel too — unlike env-var tricks, which phpunit.xml's
     * worker bootstrap outranks.
     */
    protected function beginDatabaseTransaction(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF');
        }

        $this->refreshDatabaseBeginTransaction();
    }

    private function store(string $slug): int
    {
        $user = User::factory()->create(['status' => 1]);

        return Store::query()->create([
            'user_id' => $user->id, 'slug' => $slug.Str::lower(Str::random(4)), 'color' => '#111',
            'title' => $slug, 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ])->id;
    }

    private function item(int $storeId, ?int $categoryId, string $name): Menu
    {
        if ($categoryId === 987654 && DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('An orphan category id cannot be inserted where foreign keys are enforced (MySQL / PostgreSQL).');
        }

        return Menu::query()->create([
            'store_id' => $storeId, 'category_id' => $categoryId, 'name' => $name,
            'price' => 1, 'active' => '1', 'position' => 9999,
        ]);
    }

    private function categoryId(int $storeId, string $name): int
    {
        return (int) MenuCategory::query()
            ->where('store_id', $storeId)->where('name', $name)->sole()->id;
    }

    public function test_reports_cross_tenant_and_orphan_items_without_writing(): void
    {
        $crossTenant = $this->item($this->storeA, $this->categoryId($this->storeB, 'Lunch'), 'foreign home');
        $orphan = $this->item($this->storeA, 987654, 'lost item');
        $healthy = $this->item($this->storeA, $this->categoryId($this->storeA, 'Drinks'), 'fine item');

        $this->artisan('menu:audit-placement')->expectsOutputToContain('cross-tenant items: 1')->run();
        $this->artisan('menu:audit-placement')->expectsOutputToContain('orphan live items: 1 across 1 stores')->run();

        // Read-only pass must not touch the data.
        $this->assertSame($this->categoryId($this->storeB, 'Lunch'), $crossTenant->fresh()->category_id);
        $this->assertSame(987654, $orphan->fresh()->category_id);
        $this->assertSame($this->categoryId($this->storeA, 'Drinks'), $healthy->fresh()->category_id);
    }

    public function test_fix_rehomes_non_duplicates_into_a_hidden_uncategorized_bucket(): void
    {
        $orphan = $this->item($this->storeA, 987654, 'lost item');

        $this->artisan('menu:audit-placement', ['--fix' => true])
            ->expectsOutputToContain('Fix complete: 1 items re-homed, 0 duplicate orphans soft-deleted')
            ->run();

        $bucketId = $this->categoryId($this->storeA, 'Uncategorized');
        $this->assertSame($bucketId, $orphan->fresh()->category_id);
        // The bucket is INACTIVE — the storefront skips items whose category is
        // inactive, so the rescued item never appears on a customer menu.
        $this->assertSame('0', (string) MenuCategory::query()->find($bucketId)->active);

        // Idempotent: nothing left, no second bucket, bucket stays hidden.
        $this->artisan('menu:audit-placement', ['--fix' => true])
            ->expectsOutputToContain('Nothing to fix.')->run();
        $this->assertSame(1, MenuCategory::query()->where('store_id', $this->storeA)->where('name', 'Uncategorized')->count());
        $this->assertSame('0', (string) MenuCategory::query()->find($bucketId)->fresh()->active);
    }

    public function test_fix_soft_deletes_duplicate_orphans_and_keeps_them_in_trash(): void
    {
        // chef-shafi's shape: a live $6.4 item plus a stale $4 orphan of the
        // same name (with the trailing-space variant the editor could never match).
        $live = $this->item($this->storeA, $this->categoryId($this->storeA, 'Drinks'), 'Cordon Bleu');
        $stale = $this->item($this->storeA, 987654, 'cordon bleu ');
        $unique = $this->item($this->storeA, 987654, 'lost item');

        $this->artisan('menu:audit-placement', ['--fix' => true])
            ->expectsOutputToContain('1 duplicate orphans soft-deleted')
            ->run();

        $this->assertNotNull(Menu::withTrashed()->find($stale->id)->deleted_at);
        $this->assertSame(987654, Menu::withTrashed()->find($stale->id)->category_id);
        $this->assertNull($unique->fresh()->deleted_at);
        $this->assertSame($this->categoryId($this->storeA, 'Uncategorized'), $unique->fresh()->category_id);
        $this->assertNull($live->fresh()->deleted_at);
    }

    public function test_fix_rehomes_cross_tenant_to_same_named_own_category(): void
    {
        $crossTenant = $this->item($this->storeA, $this->categoryId($this->storeB, 'Lunch'), 'foreign home');

        // A same-named category in the item's OWN store wins over Uncategorized.
        MenuCategory::query()->create([
            'store_id' => $this->storeA, 'name' => 'Lunch', 'parent' => null, 'position' => 2, 'active' => '1',
        ]);

        $this->artisan('menu:audit-placement', ['--fix' => true])->run();

        $this->assertSame($this->categoryId($this->storeA, 'Lunch'), $crossTenant->fresh()->category_id);
        $this->artisan('menu:audit-placement')->expectsOutputToContain('cross-tenant items: 0')->run();
    }

    public function test_fix_ignores_soft_deleted_orphans_and_scopes_cross_tenant_to_own_store(): void
    {
        $orphan = $this->item($this->storeA, 987654, 'lost item');
        $foreign = $this->item($this->storeB, $this->categoryId($this->storeA, 'Drinks'), 'b store item');

        // A soft-deleted orphan is invisible to the storefront — leave it.
        $this->item($this->storeA, 987654, 'deleted lost item')->delete();

        $this->artisan('menu:audit-placement', ['--fix' => true])->run();

        $this->assertSame($this->categoryId($this->storeA, 'Uncategorized'), $orphan->fresh()->category_id);
        // Cross-tenant rows always re-home to the OWNING store's bucket, so
        // store B's item never lands in store A's categories.
        $this->assertSame($this->categoryId($this->storeB, 'Uncategorized'), $foreign->fresh()->category_id);
        // One Uncategorized bucket per affected store — never more.
        $this->assertSame(1, MenuCategory::query()->where('store_id', $this->storeA)->where('name', 'Uncategorized')->count());
        $this->assertSame(1, MenuCategory::query()->where('store_id', $this->storeB)->where('name', 'Uncategorized')->count());

        // Artisan runs the command; the options array form above is the
        // contract — verify signature parsing accepts the store filter too.
        $this->assertSame(0, Artisan::call('menu:audit-placement', ['--store' => (string) $this->storeB]));
    }
}
