<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * D4 — undo/trash for menu items. Bulk delete soft-deletes (rows hidden, images
 * survive), restore brings them back, the trash view lists them, and the purge
 * command force-deletes >30-day rows.
 */
class MenuSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $settings = new class(['currency_sign' => '$', 'currency_pos' => 1, 'currency_code' => 'USD', 'theme_color' => '#111', 'site_title' => 'X'])
        {
            public function __construct(private array $d) {}

            public function __get($k)
            {
                return $this->d[$k] ?? null;
            }

            public function __isset($k)
            {
                return isset($this->d[$k]);
            }
        };
        view()->share([
            'activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true),
            'settings' => $settings, 'languages' => collect(), 'admin_languages' => collect(),
        ]);
        $this->withoutMiddleware();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        parent::tearDown();
    }

    private function scaffold(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'category_limit' => 999, 'scan_limit' => 999],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'C', 'parent' => null, 'position' => 1]);
        $menu = Menu::create([
            'store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Pizza', 'description' => 'd',
            'price' => 10, 'image' => 'd4-'.Str::random(8).'.png', 'type' => 'veg', 'active' => '1', 'position' => 1,
        ]);

        return [$owner, $r, $menu];
    }

    private function seedImage(Menu $menu): string
    {
        $dir = public_path('storage/menu');
        if (! is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $path = $dir.'/'.$menu->image;
        file_put_contents($path, 'x');
        $this->tempFiles[] = $path;

        return $path;
    }

    public function test_bulk_delete_soft_deletes_and_keeps_the_image(): void
    {
        [$owner, $r, $menu] = $this->scaffold();
        $img = $this->seedImage($menu);

        $resp = $this->actingAs($owner)->postJson(route('stores.menuBulk', $r->id), [
            'ids' => [$menu->id], 'action' => 'delete',
        ])->assertOk();

        // Soft-deleted: hidden from the default scope, present with trashed.
        $this->assertNull(Menu::find($menu->id));
        $this->assertNotNull(Menu::withTrashed()->find($menu->id)->deleted_at);
        // The image survives a soft delete (cleanup moved to forceDeleted).
        $this->assertFileExists($img);
        // Response carries the ids for Undo.
        $this->assertSame([$menu->id], $resp->json('deleted_ids'));
    }

    public function test_restore_brings_the_item_back(): void
    {
        [$owner, $r, $menu] = $this->scaffold();
        $menu->delete();
        $this->assertNull(Menu::find($menu->id));

        $this->actingAs($owner)->postJson(route('stores.menu.restore', $r->id), ['ids' => [$menu->id]])
            ->assertOk()->assertJson(['success' => true]);

        $this->assertNotNull(Menu::find($menu->id));
    }

    public function test_restore_is_tenant_scoped(): void
    {
        [$owner, $r, $menu] = $this->scaffold();
        $menu->delete();
        // A different owner cannot restore my item.
        [$other] = $this->scaffold();
        $this->actingAs($other)->postJson(route('stores.menu.restore', $r->id), ['ids' => [$menu->id]])
            ->assertNotFound();
        $this->assertNull(Menu::find($menu->id)); // still trashed
    }

    public function test_trash_view_lists_trashed_items(): void
    {
        [$owner, $r, $menu] = $this->scaffold();
        $menu->delete();

        $this->actingAs($owner)->get(route('stores.menu.trash', $r->id))
            ->assertOk()->assertSee('Pizza');
    }

    public function test_purge_force_deletes_old_trashed_items(): void
    {
        [$owner, $r, $menu] = $this->scaffold();
        $img = $this->seedImage($menu);
        $menu->delete();
        // Age the trash beyond 30 days.
        Menu::withTrashed()->where('id', $menu->id)->update(['deleted_at' => Carbon::now()->subDays(40)]);

        Artisan::call('menu:purge-trashed', ['--days' => 30]);

        $this->assertNull(Menu::withTrashed()->find($menu->id)); // gone for good
        $this->assertFileDoesNotExist($img); // image unlinked on purge
    }

    /** @return array{0: Order, 1: OrderItem} */
    private function orderLineFor(Store $store, Menu $menu): array
    {
        $order = Order::create([
            'store_id' => $store->id, 'type' => 'takeaway', 'status' => 'completed', 'track_token' => Str::random(32),
            'customer_name' => 'Guest', 'phone_number' => '0500000000', 'created_at' => now(),
        ]);
        $line = OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 2]);

        return [$order, $line];
    }

    public function test_purge_keeps_trashed_items_that_orders_still_name_and_their_lines(): void
    {
        [$owner, $r, $menu] = $this->scaffold();
        [, $line] = $this->orderLineFor($r, $menu);
        $menu->delete();
        Menu::withTrashed()->where('id', $menu->id)->update(['deleted_at' => Carbon::now()->subDays(40)]);

        Artisan::call('menu:purge-trashed', ['--days' => 30]);

        $this->assertNotNull(Menu::withTrashed()->find($menu->id)); // kept, still trashed
        $this->assertSame($menu->id, (int) DB::table('order_items')->where('id', $line->id)->value('item_id'));
    }

    public function test_deleting_an_item_for_good_keeps_its_order_lines(): void
    {
        [$owner, $r, $menu] = $this->scaffold();
        [, $line] = $this->orderLineFor($r, $menu);

        Menu::withTrashed()->whereKey($menu->id)->first()?->forceDelete();

        $this->assertSame(2, (int) DB::table('order_items')->where('id', $line->id)->value('quantity'));
        $this->assertNull(DB::table('order_items')->where('id', $line->id)->value('item_id'));
    }

    public function test_purge_leaves_recent_trash_untouched(): void
    {
        [$owner, $r, $menu] = $this->scaffold();
        $menu->delete(); // deleted just now

        Artisan::call('menu:purge-trashed', ['--days' => 30]);

        $this->assertNotNull(Menu::withTrashed()->find($menu->id));
    }
}
