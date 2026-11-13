<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Support\Sql;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * WI-09v characterisation of the owner menu maintenance endpoints
 * (User/StoreController menuBulkAction / menuRestore / menuTrash / menuUn86):
 * the store is resolved by id OR slug among the signed-in owner's stores only,
 * every item query stays inside that store, a move needs a category of the
 * same store, trash lists this store's soft-deleted items newest first, a
 * restored item whose category is gone comes back detached and inactive, and
 * un-86 clears sold-out on the given ids (or every sold-out item).
 */
class OwnerMenuMaintenanceContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shareLayoutGlobals();
        $this->withoutMiddleware();
    }

    /** @return array{0: User, 1: Store, 2: MenuCategory} */
    private function store(?User $owner = null): array
    {
        if ($owner === null) {
            $plan = TestPlans::create([
                'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
                'lifetime_price' => 1, 'status' => 1, 'settings' => ['restaurant_limit' => 99, 'menu_limit' => 999],
            ]);
            $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        }
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'wi09v-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'R',
            'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1', 'phone' => '1',
        ]);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'C', 'position' => 1]);

        return [$owner, $store, $cat];
    }

    private function item(Store $store, MenuCategory $cat, string $name, array $extra = []): Menu
    {
        return Menu::create($extra + [
            'store_id' => $store->id, 'category_id' => $cat->id, 'name' => $name, 'description' => 'd',
            'price' => 10, 'image' => 'default.png', 'type' => 'veg', 'active' => '1', 'position' => 1,
        ]);
    }

    public function test_bulk_resolves_the_store_by_slug_and_only_among_the_owners_stores(): void
    {
        [$owner, $store, $cat] = $this->store();
        $a = $this->item($store, $cat, 'A');

        $this->actingAs($owner)->postJson(route('stores.menuBulk', $store->slug), ['ids' => [$a->id], 'action' => 'deactivate'])
            ->assertOk()->assertExactJson(['success' => true, 'count' => 1]);
        $this->assertSame('0', (string) $a->fresh()->active);

        [$stranger] = $this->store();
        $this->actingAs($stranger)->postJson(route('stores.menuBulk', $store->slug), ['ids' => [$a->id], 'action' => 'activate'])
            ->assertNotFound();
        $this->actingAs($owner)->postJson(route('stores.menuBulk', $store->id), ['ids' => [999999], 'action' => 'activate'])
            ->assertOk()->assertJson(['success' => false])->assertJsonStructure(['success', 'message']);
    }

    public function test_bulk_move_needs_a_category_of_the_same_store(): void
    {
        [$owner, $store, $cat] = $this->store();
        [, $other, $otherCat] = $this->store($owner);
        $a = $this->item($store, $cat, 'A');
        $b = $this->item($other, $otherCat, 'B');

        $this->actingAs($owner)->postJson(route('stores.menuBulk', $store->id), [
            'ids' => [$a->id, $b->id], 'action' => 'move', 'category_id' => $otherCat->id,
        ])->assertNotFound();
        $this->assertSame($cat->id, (int) $a->fresh()->category_id);

        $target = MenuCategory::create(['store_id' => $store->id, 'name' => 'T', 'position' => 2]);
        $this->actingAs($owner)->postJson(route('stores.menuBulk', $store->id), [
            'ids' => [$a->id, $b->id], 'action' => 'move', 'category_id' => $target->id,
        ])->assertOk()->assertExactJson(['success' => true, 'count' => 1]);
        $this->assertSame($target->id, (int) $a->fresh()->category_id);
        $this->assertSame($otherCat->id, (int) $b->fresh()->category_id, 'another store\'s item is never touched');
    }

    public function test_trash_lists_this_stores_deleted_items_newest_first(): void
    {
        [$owner, $store, $cat] = $this->store();
        [, $other, $otherCat] = $this->store($owner);
        $old = $this->item($store, $cat, 'Old');
        $new = $this->item($store, $cat, 'New');
        $live = $this->item($store, $cat, 'Live');
        $foreign = $this->item($other, $otherCat, 'Foreign');
        foreach ([$old, $foreign] as $m) {
            $m->delete();
        }
        Menu::withTrashed()->whereKey($old->id)->update(['deleted_at' => now()->subDay()]);
        $new->delete();

        $this->actingAs($owner)->get(route('stores.menu.trash', $store->slug))
            ->assertOk()
            ->assertViewHas('store', fn ($p) => $p->is($store))
            ->assertViewHas('trashed', fn ($t) => $t->pluck('id')->all() === [$new->id, $old->id]);
        $this->assertNotNull($live->fresh());
    }

    public function test_restore_scopes_to_the_store_and_detaches_items_whose_category_is_gone(): void
    {
        [$owner, $store, $cat] = $this->store();
        [, $other, $otherCat] = $this->store($owner);
        $kept = $this->item($store, $cat, 'Kept');
        $orphan = $this->item($store, $cat, 'Orphan');
        $foreign = $this->item($other, $otherCat, 'Foreign');
        foreach ([$kept, $orphan, $foreign] as $m) {
            $m->delete();
        }
        // The legacy orphan shape: an item pointing at a category row that no
        // longer exists (the foreign-key check is deferred past the test's
        // rolled-back transaction, so the dangling id can be written).
        $driver = DB::getDriverName();
        if ($driver === 'pgsql' && ! (DB::selectOne("SELECT current_setting('is_superuser') = 'on' AS su")->su ?? false)) {
            $this->markTestSkipped('PostgreSQL only allows a dangling category id when the role can disable FK triggers.');
        }
        if ($driver === 'sqlite') {
            DB::statement('PRAGMA defer_foreign_keys = ON');
            Menu::withTrashed()->whereKey($orphan->id)->update(['category_id' => 999999]);
        } else {
            Sql::withoutForeignKeyChecks(fn () => Menu::withTrashed()->whereKey($orphan->id)->update(['category_id' => 999999]));
        }

        $this->actingAs($owner)->postJson(route('stores.menu.restore', $store->slug), ['ids' => [$kept->id, $orphan->id, $foreign->id]])
            ->assertOk()->assertExactJson(['success' => true, 'count' => 2]);

        $this->assertSame($cat->id, (int) $kept->fresh()->category_id);
        $this->assertSame('1', (string) $kept->fresh()->active);
        $this->assertNull($orphan->fresh()->category_id);
        $this->assertSame('0', (string) $orphan->fresh()->active);
        $this->assertNull(Menu::find($foreign->id), 'another store\'s item stays in its trash');

        $this->actingAs($owner)->post(route('stores.menu.restore', $store->id), ['ids' => [$kept->id]])
            ->assertRedirect();
    }

    public function test_un86_clears_sold_out_for_the_given_ids_or_all(): void
    {
        [$owner, $store, $cat] = $this->store();
        [, $other, $otherCat] = $this->store($owner);
        $a = $this->item($store, $cat, 'A', ['is_sold_out' => 1]);
        $b = $this->item($store, $cat, 'B', ['is_sold_out' => 1]);
        $f = $this->item($other, $otherCat, 'F', ['is_sold_out' => 1]);

        $this->actingAs($owner)->postJson(route('stores.menu.un86', $store->id), ['ids' => [$a->id, $f->id]])
            ->assertOk()->assertExactJson(['success' => true, 'count' => 1]);
        $this->assertSame(0, (int) $a->fresh()->is_sold_out);
        $this->assertSame(1, (int) $b->fresh()->is_sold_out);

        $this->actingAs($owner)->postJson(route('stores.menu.un86', $store->slug))
            ->assertOk()->assertExactJson(['success' => true, 'count' => 1]);
        $this->assertSame(0, (int) $b->fresh()->is_sold_out);
        $this->assertSame(1, (int) $f->fresh()->is_sold_out);
    }
}
