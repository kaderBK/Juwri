<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\ActivityLog;
use App\Models\ImageMenu;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Ai\Models\TranslationMemory;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * WI-09v characterisation of the owner menu editor, item group
 * (User/Concerns/ManagesMenu: addMenuItem, updateMenuItem, deleteMenuItem,
 * reorderMenuItem and the image-menu add/update/delete/reorder): every
 * column the item form writes, the per-category item limit, the editing
 * language cookie, gallery handling, the audit rows, store scoping, and the
 * image-menu JSON-vs-redirect answer.
 */
class OwnerMenuItemsContractTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    protected function tearDown(): void
    {
        unset($_COOKIE['Quick_user_lang_code']);
        foreach ($this->tmpFiles as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function owner(array $settings = []): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1,
            'settings' => $settings + ['restaurant_limit' => 9, 'menu_limit' => 999, 'category_limit' => 999],
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    /** @return array{0: Store, 1: MenuCategory} */
    private function store(User $owner): array
    {
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'itm-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'R',
            'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1', 'phone' => '1',
        ]);

        return [$store, MenuCategory::create(['store_id' => $store->id, 'name' => 'C'])];
    }

    private function item(Store $store, MenuCategory $cat, array $extra = []): Menu
    {
        return Menu::create($extra + [
            'store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Burger', 'description' => 'Juicy',
            'price' => 10, 'image' => 'old.png', 'type' => 'veg', 'active' => '1', 'position' => 1,
        ]);
    }

    public function test_add_menu_item_writes_every_form_column(): void
    {
        $owner = $this->owner();
        [$store, $cat] = $this->store($owner);

        $this->actingAs($owner)->postJson(route('stores.addMenuItem', $store->id), [
            'category_id' => $cat->id, 'name' => 'Soup', 'price' => '7.5', 'cost' => '2.25', 'type' => 'nonveg',
            'description' => '<p>Hot <script>x</script><b>soup</b></p>', 'image' => 'lib/dir/pick.png',
            'gallery' => ['g1.png', '', 'g2.png', 'g1.png', 'g3.png', 'g4.png', 'g5.png', 'g6.png'],
            'allergies' => ['3', '5'], 'active' => '1', 'is_vegan' => '0', 'is_popular' => '1', 'spice_level' => '2',
            'available_from' => '10:00', 'available_days' => 'sun,SAT,nope', 'available_order_types' => ['takeaway', 'x'],
            'calories' => '450', 'protein' => '12.5', 'track_inventory' => '1', 'stock_qty' => '8', 'course' => '2',
        ])->assertOk()->assertExactJson(['success' => true, 'message' => ___('Saved Successfully')]);

        $m = Menu::sole();
        $this->assertSame([
            'store_id' => $store->id, 'name' => 'Soup', 'description' => '<p>Hot x<b>soup</b></p>', 'type' => 'nonveg',
            'image' => 'pick.png', 'gallery_images' => ['g1.png', 'g2.png', 'g3.png', 'g4.png', 'g5.png'],
            'price' => 7.5, 'cost' => 2.25, 'category_id' => $cat->id, 'allergies' => '3,5', 'active' => '1',
            'is_vegan' => 1, 'is_halal' => 0, 'is_gluten_free' => 0, 'spice_level' => 2, 'is_popular' => 1,
            'is_sold_out' => 0, 'is_daily_special' => 0, 'available_from' => '10:00', 'available_to' => null,
            'available_days' => 'sun,sat', 'available_order_types' => 'takeaway', 'calories' => 450, 'protein' => 12.5,
            'carbs' => null, 'fat' => null, 'track_inventory' => 1, 'stock_qty' => 8, 'low_stock_threshold' => 5,
            'course' => 2, 'prep_minutes' => null,
        ], $this->row($m));

        $log = ActivityLog::where('action', 'menu.create')->sole();
        $this->assertSame([$m->id, $store->id, ['name' => 'Soup']], [(int) $log->subject_id, (int) $log->store_id, $log->meta]);
    }

    public function test_add_menu_item_defaults_validation_and_limit(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        $owner = $this->owner(['menu_limit' => 1]);
        [$store, $cat] = $this->store($owner);

        $this->actingAs($owner)->postJson(route('stores.addMenuItem', $store->id), ['category_id' => $cat->id, 'name' => 'Tea', 'price' => '1', 'type' => 'veg'])
            ->assertExactJson(['success' => true, 'message' => ___('Saved Successfully')]);
        $m = Menu::sole();
        $this->assertSame(['default.png', null, null, '0', 0, 1, 5, null, null], [
            $m->image, $m->gallery_images, $m->description, (string) $m->getRawOriginal('active'), (int) $m->spice_level,
            (int) $m->course, (int) $m->low_stock_threshold, $m->cost, $m->allergies,
        ]);

        $this->actingAs($owner)->postJson(route('stores.addMenuItem', $store->id), ['category_id' => $cat->id, 'name' => 'Two', 'price' => '1', 'type' => 'veg'])
            ->assertExactJson(['success' => false, 'message' => ___('Limit exceeded, please upgrade your membership.')]);
        $cat2 = MenuCategory::create(['store_id' => $store->id, 'name' => 'D']);
        $this->actingAs($owner)->postJson(route('stores.addMenuItem', $store->id), ['category_id' => $cat2->id, 'name' => 'Two', 'price' => '1', 'type' => 'veg'])
            ->assertJson(['success' => true]);

        $this->actingAs($owner)->postJson(route('stores.addMenuItem', $store->id), ['name' => '', 'price' => '-1', 'type' => 'x'])
            ->assertOk()->assertExactJson(['success' => false, 'message' => implode('<br>', [
                'The category id field is required.', 'The name field is required.', 'The price field must be at least 0.', 'The selected type is invalid.',
            ])]);
        $this->actingAs($this->owner())->postJson(route('stores.addMenuItem', $store->id), ['category_id' => $cat->id, 'name' => 'X', 'price' => '1', 'type' => 'veg'])
            ->assertNotFound(); // BL-043: a stranger's request on this store is refused by the owner tenant scope (404)
        $this->assertSame(2, Menu::count());
    }

    public function test_add_menu_item_resolves_an_unbound_store_by_slug(): void
    {
        $owner = $this->owner();
        [$store, $cat] = $this->store($owner);
        $this->withoutMiddleware(); // no route-model binding: the {store} slug is resolved in the method

        $this->actingAs($owner)->postJson(route('stores.addMenuItem', $store->slug), ['category_id' => $cat->id, 'name' => 'Tea', 'price' => '1', 'type' => 'veg'])
            ->assertExactJson(['success' => true, 'message' => ___('Saved Successfully')]);
        $this->assertSame($store->id, (int) Menu::sole()->store_id);
    }

    public function test_update_menu_item_without_language_cookie(): void
    {
        $owner = $this->owner();
        [$store, $cat] = $this->store($owner);
        $m = $this->item($store, $cat, ['gallery_images' => ['a.png', 'b.png'], 'is_halal' => 1, 'stock_qty' => 3]);

        $this->actingAs($owner)->postJson(route('stores.updateMenuItem', $store->id), [
            'id' => $m->id, 'category_id' => $cat->id, 'name' => 'Big', 'price' => '12', 'type' => 'veg',
            'description' => '<i>new</i><img src=x>', 'remove_gallery_images' => ['a.png'], 'fat' => '3',
        ])->assertOk()->assertExactJson(['success' => true, 'message' => ___('Saved Successfully')]);

        $m->refresh();
        $row = $this->row($m);
        $this->assertSame(['Big', '<i>new</i>', 'old.png', ['b.png'], 12.0, '0', 0, null, 3.0, null], [
            $row['name'], $row['description'], $row['image'], $row['gallery_images'], $row['price'], $row['active'],
            $row['is_halal'], $row['stock_qty'], $row['fat'], $row['allergies'],
        ]);
        $this->assertEquals(new \stdClass, $m->translations);
        $log = ActivityLog::where('action', 'menu.update')->sole();
        $this->assertSame([$m->id, ['name' => 'Big']], [(int) $log->subject_id, $log->meta]);

        // gallery[] is authoritative when sent; an empty image field means "Remove".
        $this->actingAs($owner)->postJson(route('stores.updateMenuItem', $store->id), [
            'id' => $m->id, 'category_id' => $cat->id, 'name' => 'Big', 'price' => '12', 'type' => 'veg',
            'gallery' => ['z.png', 'y.png'], 'image' => '',
        ])->assertJson(['success' => true]);
        $m->refresh();
        $this->assertSame([['z.png', 'y.png'], 'default.png', null], [$m->gallery_images, $m->image, $m->description]);

        $this->actingAs($owner)->postJson(route('stores.updateMenuItem', $store->id), [
            'id' => $m->id, 'category_id' => $cat->id, 'name' => 'Big', 'price' => '12', 'type' => 'veg', 'gallery' => [], 'image' => 'x/lib.png',
        ]);
        $m->refresh();
        $this->assertSame([null, 'lib.png'], [$m->gallery_images, $m->image]);
    }

    public function test_update_menu_item_with_language_cookie(): void
    {
        $owner = $this->owner();
        [$store, $cat] = $this->store($owner);
        StoreOption::updateStoreOption($store->id, 'default_language', 'en');
        $m = $this->item($store, $cat);
        $body = ['id' => $m->id, 'category_id' => $cat->id, 'price' => '10', 'type' => 'veg'];

        $_COOKIE['Quick_user_lang_code'] = 'fr';
        $this->actingAs($owner)->postJson(route('stores.updateMenuItem', $store->id), $body + ['name' => 'Hamburger', 'description' => 'Juteux']);
        $m->refresh();
        $this->assertSame(['Burger', 'Juicy'], [$m->getRawOriginal('name'), $m->getRawOriginal('description')]);
        $this->assertEquals((object) ['fr' => (object) ['title' => 'Hamburger', 'description' => 'Juteux']], $m->translations);
        $this->assertEqualsCanonicalizing(['Burger' => 'Hamburger', 'Juicy' => 'Juteux'],
            TranslationMemory::pluck('target_text', 'source_text')->all());

        $_COOKIE['Quick_user_lang_code'] = 'de';
        $this->actingAs($owner)->postJson(route('stores.updateMenuItem', $store->id), $body + ['name' => 'Burger DE']);
        $this->assertSame(3, TranslationMemory::count(), 'an empty description is not remembered');
        $this->assertEquals((object) ['title' => 'Burger DE', 'description' => null], $m->fresh()->translations->de);

        $_COOKIE['Quick_user_lang_code'] = 'en';
        $this->actingAs($owner)->postJson(route('stores.updateMenuItem', $store->id), $body + ['name' => 'Beef burger', 'description' => '<b>ok</b><u>u</u>']);
        $m->refresh();
        $this->assertSame(['Beef burger', '<b>ok</b>u'], [$m->getRawOriginal('name'), $m->getRawOriginal('description')]);
        $this->assertSame(3, TranslationMemory::count());
    }

    public function test_update_menu_item_guards(): void
    {
        $owner = $this->owner();
        [$store, $cat] = $this->store($owner);
        [$otherPost, $otherCat] = $this->store($this->owner());
        $foreign = $this->item($otherPost, $otherCat);
        $mine = $this->item($store, $cat);
        $body = ['category_id' => $cat->id, 'name' => 'Hack', 'price' => '1', 'type' => 'veg'];

        $this->actingAs($owner)->postJson(route('stores.updateMenuItem', $store->id), $body + ['id' => $foreign->id])->assertNotFound();
        $this->actingAs($this->owner())->postJson(route('stores.updateMenuItem', $store->id), $body + ['id' => $mine->id])
            ->assertNotFound(); // BL-043: a stranger's request on this store is refused by the owner tenant scope (404)
        $this->actingAs($owner)->postJson(route('stores.updateMenuItem', $store->id), ['id' => $mine->id, 'name' => 'x'])
            ->assertOk()->assertJson(['success' => false])->assertJsonPath('message', 'The category id field is required.<br>The price field is required.<br>The type field is required.');
        $this->assertSame('Burger', $mine->fresh()->getRawOriginal('name'));
    }

    public function test_delete_and_reorder_menu_items(): void
    {
        $owner = $this->owner();
        [$store, $cat] = $this->store($owner);
        [$otherPost, $otherCat] = $this->store($this->owner());
        $a = $this->item($store, $cat, ['name' => 'A']);
        $b = $this->item($store, $cat, ['name' => 'B']);
        $foreign = $this->item($otherPost, $otherCat, ['position' => 9]);

        $this->actingAs($owner)->postJson(route('stores.reorderMenuItem', $store->id), ['position' => [$b->id, $a->id, $foreign->id]])
            ->assertOk()->assertExactJson(['success' => true, 'message' => ___('Updated Successfully')]);
        $this->assertSame([1, 0, 9], [(int) $a->fresh()->position, (int) $b->fresh()->position, (int) $foreign->fresh()->position]);
        $this->actingAs($this->owner())->postJson(route('stores.reorderMenuItem', $store->id), ['position' => [$a->id]])
            ->assertNotFound(); // BL-043: a stranger's request on this store is refused by the owner tenant scope (404)
        $this->assertSame(1, (int) $a->fresh()->position);

        $this->actingAs($owner)->postJson(route('stores.deleteMenuItem', $store->id), [])
            ->assertOk()->assertExactJson(['success' => false, 'message' => 'The id field is required.']);
        $this->actingAs($owner)->postJson(route('stores.deleteMenuItem', $store->id), ['id' => $foreign->id])->assertNotFound();
        $this->actingAs($this->owner())->postJson(route('stores.deleteMenuItem', $store->id), ['id' => $a->id])
            ->assertNotFound(); // BL-043: a stranger's request on this store is refused by the owner tenant scope (404)
        $this->actingAs($owner)->postJson(route('stores.deleteMenuItem', $store->id), ['id' => $a->id])
            ->assertOk()->assertExactJson(['success' => true, 'message' => ___('Deleted Successfully')]);
        $this->assertNull(Menu::find($a->id));
        $this->assertNotNull(Menu::withTrashed()->find($a->id), 'soft delete (D4 trash)');
        $log = ActivityLog::where('action', 'menu.delete')->sole();
        $this->assertSame([null, ['id' => $a->id, 'name' => 'A']], [$log->subject_id, $log->meta]);
    }

    public function test_image_menu_answers_json_to_ajax_and_redirects_forms(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        $owner = $this->owner(['menu_limit' => 2]);
        [$store] = $this->store($owner);
        $a = ImageMenu::create(['store_id' => $store->id, 'name' => 'A', 'image' => 'a.jpg', 'active' => '1', 'position' => 3]);
        $b = ImageMenu::create(['store_id' => $store->id, 'name' => 'B', 'image' => 'b.jpg', 'active' => '1', 'position' => 3]);
        [$otherPost] = $this->store($this->owner());
        $foreign = ImageMenu::create(['store_id' => $otherPost->id, 'name' => 'F', 'image' => 'f.jpg', 'active' => '1', 'position' => 3]);

        // add: validation, limit (counts image rows of the store), stranger
        $this->actingAs($owner)->postJson(route('stores.addImageMenuItem', $store->id), ['name' => ''])
            ->assertOk()->assertExactJson(['success' => false, 'message' => 'The name field is required.<br>The image field is required.']);
        $this->actingAs($owner)->from('/menu')->post(route('stores.addImageMenuItem', $store->id), ['name' => ''], ['Accept' => 'text/html'])
            ->assertRedirect('/menu');
        $this->assertSame(['error', 'The name field is required.<br>The image field is required.'], [session('app_alert_type'), session('app_alert_message')]);
        $this->actingAs($owner)->postJson(route('stores.addImageMenuItem', $store->id), ['name' => 'C', 'image' => UploadedFile::fake()->image('c.jpg', 20, 20)])
            ->assertExactJson(['success' => false, 'message' => ___('Limit exceeded, please upgrade your membership.')]);
        $this->actingAs($this->owner())->postJson(route('stores.addImageMenuItem', $store->id), ['name' => 'C'])
            ->assertNotFound(); // BL-043: a stranger's request on this store is refused by the owner tenant scope (404)

        // update: scoped, name + active, no file keeps the image
        $this->actingAs($owner)->postJson(route('stores.updateImageMenuItem', $store->id), ['id' => $foreign->id, 'name' => 'X'])->assertNotFound();
        $this->actingAs($owner)->postJson(route('stores.updateImageMenuItem', $store->id), ['id' => $a->id, 'name' => 'A2'])
            ->assertExactJson(['success' => true, 'message' => ___('Saved Successfully')]);
        $this->assertSame(['A2', 'a.jpg', '0'], [$a->fresh()->name, $a->fresh()->image, (string) $a->fresh()->active]);
        $this->actingAs($owner)->from('/menu')->post(route('stores.updateImageMenuItem', $store->id), ['id' => $a->id, 'name' => 'A3', 'active' => '1'], ['Accept' => 'text/html'])
            ->assertRedirect('/menu');
        $this->assertSame(['success', ___('Saved Successfully')], [session('app_alert_type'), session('app_alert_message')]);
        $this->assertSame('1', (string) $a->fresh()->active);
        $this->actingAs($owner)->postJson(route('stores.updateImageMenuItem', $store->id), ['name' => 'A'])
            ->assertExactJson(['success' => false, 'message' => 'The id field is required.']);

        // reorder: own rows only, always success
        $this->actingAs($owner)->postJson(route('stores.reorderImageMenuItem', $store->id), ['position' => [$b->id, $foreign->id, $a->id]])
            ->assertExactJson(['success' => true, 'message' => ___('Updated Successfully')]);
        $this->assertSame([2, 0, 3], [(int) $a->fresh()->position, (int) $b->fresh()->position, (int) $foreign->fresh()->position]);

        // delete: scoped
        $this->actingAs($owner)->postJson(route('stores.deleteImageMenuItem', $store->id), ['id' => $foreign->id])->assertNotFound();
        $this->actingAs($this->owner())->postJson(route('stores.deleteImageMenuItem', $store->id), ['id' => $b->id])
            ->assertNotFound(); // BL-043: a stranger's request on this store is refused by the owner tenant scope (404)
        $this->actingAs($owner)->postJson(route('stores.deleteImageMenuItem', $store->id), [])
            ->assertExactJson(['success' => false, 'message' => 'The id field is required.']);
        $this->actingAs($owner)->postJson(route('stores.deleteImageMenuItem', $store->id), ['id' => $b->id])
            ->assertExactJson(['success' => true, 'message' => ___('Deleted Successfully')]);
        $this->assertNull(ImageMenu::find($b->id));

        // add succeeds under the limit
        $this->actingAs($owner)->postJson(route('stores.addImageMenuItem', $store->id), ['name' => 'New', 'image' => UploadedFile::fake()->image('n.jpg', 20, 20)])
            ->assertExactJson(['success' => true, 'message' => ___('Saved Successfully')]);
        $new = ImageMenu::where('name', 'New')->sole();
        $this->tmpFiles[] = public_path('storage/menu/'.$new->image);
        $this->tmpFiles[] = public_path('storage/menu/'.pathinfo($new->image, PATHINFO_FILENAME).'-w900.webp');
        $this->assertSame([$store->id, '0'], [(int) $new->store_id, (string) $new->active]);
    }

    /** @return array<string, mixed> */
    private function row(Menu $m): array
    {
        $raw = $m->getAttributes();
        $out = [];
        foreach (['store_id', 'name', 'description', 'type', 'image', 'gallery_images', 'price', 'cost', 'category_id', 'allergies',
            'active', 'is_vegan', 'is_halal', 'is_gluten_free', 'spice_level', 'is_popular', 'is_sold_out', 'is_daily_special',
            'available_from', 'available_to', 'available_days', 'available_order_types', 'calories', 'protein', 'carbs', 'fat',
            'track_inventory', 'stock_qty', 'low_stock_threshold', 'course', 'prep_minutes'] as $k) {
            $v = in_array($k, ['price', 'cost'], true) ? $m->getAttribute($k) : ($raw[$k] ?? null); // P1-05: *_minor columns
            $out[$k] = match (true) {
                $k === 'gallery_images' => $m->gallery_images,
                $v === null => null,
                in_array($k, ['price', 'cost', 'protein', 'carbs', 'fat'], true) => (float) $v,
                in_array($k, ['store_id', 'category_id', 'is_vegan', 'is_halal', 'is_gluten_free', 'spice_level', 'is_popular',
                    'is_sold_out', 'is_daily_special', 'calories', 'track_inventory', 'stock_qty', 'low_stock_threshold', 'course', 'prep_minutes'], true) => (int) $v,
                $k === 'available_from' || $k === 'available_to' => substr((string) $v, 0, 5),
                default => (string) $v,
            };
        }

        return $out;
    }
}
