<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Juwri\Modules\Tables\Models\WaiterCall;
use Tests\Support\TestPlans;
use Tests\TestCase;

class MenuOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $settings = new class(['theme_color' => '#111', 'site_title' => 'T', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
        {
            public function __construct(private array $data) {}

            public function __get(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function __set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function __isset(string $key): bool
            {
                return array_key_exists($key, $this->data);
            }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect(), 'pages' => collect()]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    private function makeUser(): User
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000, 'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999], 'status' => 1]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function makeRestaurant(User $owner): Store
    {
        $title = 'R'.Str::random(6);

        return Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function makeItem(Store $store, array $attrs = []): Menu
    {
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat']);

        return Menu::create(array_merge(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Item', 'price' => 10.00, 'active' => 1], $attrs));
    }

    // ── #1 DnD Reorder ──

    /** Reorder menu items persists positions */
    public function test_reorder_menu_item_persists_position(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $item1 = $this->makeItem($store);
        $item2 = $this->makeItem($store);

        $this->actingAs($owner)
            ->postJson(route('stores.reorderMenuItem', $store), [
                'position' => [$item2->id, $item1->id], // swap
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertEquals(0, Menu::find($item2->id)->position);
        $this->assertEquals(1, Menu::find($item1->id)->position);
    }

    /** Tenant isolation: other owner's reorder is silently ignored */
    public function test_reorder_ignored_for_wrong_owner(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $item = $this->makeItem($store, ['position' => 5]);

        // Other owner tries to reorder owner's items — controller checks user_id, silently skips
        $this->actingAs($other)
            ->postJson(route('stores.reorderMenuItem', $store), [
                'position' => [$item->id],
            ])
            ->assertNotFound(); // BL-043: the owner tenant scope hides the other account's row (404)

        $this->assertEquals(5, Menu::find($item->id)->position);
    }

    // ── #2 Bulk actions ──

    /** Bulk deactivate sets active=0 */
    public function test_bulk_deactivate(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $item = $this->makeItem($store, ['active' => 1]);

        $this->actingAs($owner)
            ->postJson(route('stores.menuBulk', $store), [
                'ids' => [$item->id],
                'action' => 'deactivate',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('items', ['id' => $item->id, 'active' => 0]);
    }

    /** Bulk activate sets active=1 */
    public function test_bulk_activate(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $item = $this->makeItem($store, ['active' => 0]);

        $this->actingAs($owner)
            ->postJson(route('stores.menuBulk', $store), [
                'ids' => [$item->id],
                'action' => 'activate',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('items', ['id' => $item->id, 'active' => 1]);
    }

    /** Bulk delete removes items */
    public function test_bulk_delete(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $item = $this->makeItem($store);

        $this->actingAs($owner)
            ->postJson(route('stores.menuBulk', $store), [
                'ids' => [$item->id],
                'action' => 'delete',
            ])
            ->assertOk();

        // D4: bulk delete now soft-deletes (reversible for 30 days).
        $this->assertSoftDeleted('items', ['id' => $item->id]);
    }

    /** Bulk duplicate creates copy */
    public function test_bulk_duplicate(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $item = $this->makeItem($store);

        $this->actingAs($owner)
            ->postJson(route('stores.menuBulk', $store), [
                'ids' => [$item->id],
                'action' => 'duplicate',
            ])
            ->assertOk();

        $this->assertEquals(2, Menu::where('store_id', $store->id)->count());
    }

    /** Bulk move changes category */
    public function test_bulk_move_category(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $item = $this->makeItem($store);
        $newCat = MenuCategory::create(['store_id' => $store->id, 'name' => 'NewCat']);

        $this->actingAs($owner)
            ->postJson(route('stores.menuBulk', $store), [
                'ids' => [$item->id],
                'action' => 'move',
                'category_id' => $newCat->id,
            ])
            ->assertOk();

        $this->assertDatabaseHas('items', ['id' => $item->id, 'category_id' => $newCat->id]);
    }

    /** Bulk tenant isolation: cannot affect another owner's items */
    public function test_bulk_tenant_isolation(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $item = $this->makeItem($store, ['active' => 1]);

        $this->actingAs($other)
            ->postJson(route('stores.menuBulk', $store), [
                'ids' => [$item->id],
                'action' => 'deactivate',
            ])
            ->assertNotFound(); // ownerRestaurant check → 404

        $this->assertDatabaseHas('items', ['id' => $item->id, 'active' => 1]);
    }

    // ── #3 Clone restaurant ──

    /** Clone duplicates restaurant with its menu */
    public function test_clone_restaurant_creates_copy(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $item = $this->makeItem($store);

        $this->actingAs($owner)
            ->postJson(route('stores.clone', $store))
            ->assertOk()
            ->assertJson(['success' => true]);

        // Two restaurants now (original + clone)
        $this->assertEquals(2, Store::where('user_id', $owner->id)->count());
        // Clone has the menu item
        $clone = Store::where('user_id', $owner->id)->where('id', '!=', $store->id)->first();
        $this->assertEquals(1, Menu::where('store_id', $clone->id)->count());
    }

    /** Clone copies modifier groups, extras and their library attachments */
    public function test_clone_copies_modifiers_extras_and_attachments(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $item = $this->makeItem($store);

        // Per-item modifier group + extra
        MenuVariantOption::create([
            'store_id' => $store->id, 'menu_id' => $item->id,
            'title' => 'Size', 'options' => ['Small', 'Large'], 'option_prices' => [10, 15],
        ]);
        MenuExtra::create([
            'store_id' => $store->id, 'menu_id' => $item->id,
            'title' => 'Cheese', 'price' => 2,
        ]);
        // Library (global) extra attached via pivot
        $globalExtra = MenuExtra::create([
            'store_id' => $store->id, 'menu_id' => null, 'is_global' => true,
            'title' => 'Sauce', 'price' => 1,
        ]);
        $item->attachedExtras()->attach($globalExtra->id, ['position' => 0, 'active' => true]);

        $res = $this->actingAs($owner)
            ->postJson(route('stores.clone', $store))
            ->assertOk()->assertJson(['success' => true]);

        $clone = Store::where('user_id', $owner->id)->where('id', '!=', $store->id)->firstOrFail();
        $newItem = Menu::where('store_id', $clone->id)->firstOrFail();

        // Per-item group followed its item
        $group = MenuVariantOption::where('store_id', $clone->id)->where('menu_id', $newItem->id)->first();
        $this->assertNotNull($group);
        $this->assertEquals(['Small', 'Large'], (array) $group->options);

        // Per-item + global extras cloned
        $this->assertDatabaseHas('modifiers', ['store_id' => $clone->id, 'menu_id' => $newItem->id, 'title' => 'Cheese']);
        $newGlobal = MenuExtra::where('store_id', $clone->id)->where('is_global', true)->first();
        $this->assertNotNull($newGlobal);

        // Pivot attachment re-created with mapped ids
        $this->assertDatabaseHas('item_modifier_group', ['menu_id' => $newItem->id, 'modifier_id' => $newGlobal->id, 'modifier_group_id' => $newGlobal->modifier_group_id]);

        // Counts returned for the dialog
        $res->assertJsonPath('counts.items', 1)->assertJsonPath('counts.extras', 2)->assertJsonPath('counts.modifiers', 1);
    }

    /** Clone consumes a restaurant slot: blocked at plan limit */
    public function test_clone_blocked_when_restaurant_limit_reached(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        $plan = TestPlans::create(['name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000, 'settings' => ['restaurant_limit' => 1, 'menu_limit' => 999], 'status' => 1]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = $this->makeRestaurant($owner); // uses the only slot

        $this->actingAs($owner)
            ->postJson(route('stores.clone', $store))
            ->assertStatus(422);

        $this->assertEquals(1, Store::where('user_id', $owner->id)->count());
    }

    /** Clone tenant isolation */
    public function test_clone_tenant_isolation(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        $this->actingAs($other)
            ->postJson(route('stores.clone', $store))
            ->assertNotFound();
    }

    // ── Server-rendered QR (jquery-qrcode plugin no longer ships) ──

    /** QR endpoint returns an SVG for the owner, encoding the public URL */
    public function test_qr_svg_renders_for_owner(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        $res = $this->actingAs($owner)
            ->get(route('stores.qrSvg', $store).'?fg=%230a0a0a&bg=%23ffffff&padding=2');

        $res->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $this->assertStringContainsString('<svg', $res->getContent());
    }

    /** QR endpoint ignores malformed colors instead of erroring */
    public function test_qr_svg_survives_bad_color_input(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        $this->actingAs($owner)
            ->get(route('stores.qrSvg', $store).'?fg=<script>&bg=nope&padding=99')
            ->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    }

    /** QR endpoint tenant isolation */
    public function test_qr_svg_blocks_other_owner(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        $this->actingAs($other)
            ->get(route('stores.qrSvg', $store))
            ->assertNotFound();
    }

    // ── #10 CSV import/export ──

    /** CSV export returns CSV content */
    public function test_csv_export_returns_csv(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $this->makeItem($store);

        $response = $this->actingAs($owner)
            ->get(route('stores.menuExportCsv', $store));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    }

    /** CSV import creates menu items */
    public function test_csv_import_creates_items(): void
    {
        Storage::fake('local');
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        $csv = "name,price,description,category_id,type,active\nImported Item,12.50,desc,,veg,1";
        $file = UploadedFile::fake()->createWithContent('menu.csv', $csv);

        $this->actingAs($owner)
            ->post(route('stores.menuImportCsv', $store), ['csv_file' => $file])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('items', [
            'store_id' => $store->id,
            'name' => 'Imported Item',
            'price_minor' => 1250,
        ]);
    }

    /** A ragged row (fewer/more cells than the header) is tolerated, not a 500 */
    public function test_csv_import_tolerates_ragged_rows(): void
    {
        Storage::fake('local');
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        // Row 2 has too few cells, row 3 too many, row 4 is good.
        $csv = "name,price,description,category_id,type,active\n"
             ."Short Row,5.00\n"
             ."Long Row,7.00,desc,,veg,1,extra,junk\n"
             .'Good Row,9.00,desc,,veg,1';
        $file = UploadedFile::fake()->createWithContent('menu.csv', $csv);

        $this->actingAs($owner)
            ->post(route('stores.menuImportCsv', $store), ['csv_file' => $file])
            ->assertOk() // must NOT 500
            ->assertJson(['success' => true]);

        // All three rows have a name+price so all import; the point is no crash.
        $this->assertDatabaseHas('items', ['store_id' => $store->id, 'name' => 'Good Row']);
        $this->assertDatabaseHas('items', ['store_id' => $store->id, 'name' => 'Long Row']);
    }

    /** A `category` NAME column resolves (creating the category) instead of needing category_id */
    public function test_csv_import_accepts_category_name(): void
    {
        Storage::fake('local');
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        $csv = "name,price,category\nLatte,4.00,Drinks";
        $file = UploadedFile::fake()->createWithContent('menu.csv', $csv);

        $this->actingAs($owner)
            ->post(route('stores.menuImportCsv', $store), ['csv_file' => $file])
            ->assertOk()
            ->assertJson(['success' => true]);

        $cat = MenuCategory::where('store_id', $store->id)->where('name', 'Drinks')->first();
        $this->assertNotNull($cat, 'category created from name');
        $this->assertDatabaseHas('items', [
            'store_id' => $store->id,
            'name' => 'Latte',
            'category_id' => $cat->id,
        ]);
    }

    /** A UTF-8 BOM on the first header cell (our own export) does not break the id column */
    public function test_csv_import_strips_bom_from_header(): void
    {
        Storage::fake('local');
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Cat']);
        $item = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Old', 'price' => 1.00, 'active' => 1]);

        // BOM-prefixed header, updating the existing item by id.
        $csv = "\xEF\xBB\xBF"."id,name,price,category_id\n{$item->id},New Name,3.50,{$cat->id}";
        $file = UploadedFile::fake()->createWithContent('menu.csv', $csv);

        $this->actingAs($owner)
            ->post(route('stores.menuImportCsv', $store), ['csv_file' => $file])
            ->assertOk()
            ->assertJson(['success' => true, 'updated' => 1]);

        // The id column matched (BOM stripped) so the row updated instead of inserting.
        $this->assertSame('New Name', $item->fresh()->name);
        $this->assertSame(1, Menu::where('store_id', $store->id)->count());
    }

    /** Rows missing a name/price are skipped and reported, not silently dropped */
    public function test_csv_import_reports_skipped_rows(): void
    {
        Storage::fake('local');
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        $csv = "name,price\nGood,5.00\n,9.00\nBad Price,-2";
        $file = UploadedFile::fake()->createWithContent('menu.csv', $csv);

        $response = $this->actingAs($owner)
            ->post(route('stores.menuImportCsv', $store), ['csv_file' => $file])
            ->assertOk()
            ->assertJson(['success' => true, 'imported' => 1, 'skipped' => 2]);

        $this->assertDatabaseHas('items', ['store_id' => $store->id, 'name' => 'Good']);
        $this->assertDatabaseMissing('items', ['store_id' => $store->id, 'name' => 'Bad Price']);
    }

    /** A header-only file imports nothing without erroring */
    public function test_csv_import_header_only_file(): void
    {
        Storage::fake('local');
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        $file = UploadedFile::fake()->createWithContent('menu.csv', "name,price\n");

        $this->actingAs($owner)
            ->post(route('stores.menuImportCsv', $store), ['csv_file' => $file])
            ->assertOk()
            ->assertJson(['success' => true, 'imported' => 0, 'skipped' => 0]);
    }

    // ── #30 Call waiter ──

    /** Call waiter creates WaiterCall row */
    public function test_call_waiter_creates_record(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        $this->postJson(route('store.callTheWaiter', $store), [
            'table' => 3,
            'type' => 'call_waiter',
        ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('waiter_calls', [
            'store_id' => $store->id,
            'table_no' => 3,
            'type' => 'call_waiter',
        ]);
    }

    /** Call waiter validates required table */
    public function test_call_waiter_requires_table(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner);

        $this->postJson(route('store.callTheWaiter', $store), ['type' => 'call_waiter'])
            ->assertOk()
            ->assertJson(['success' => false]);
    }
}
