<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StaffMember;
use App\Services\OrderApiService;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P1 (IP12): POS print/station settings CRUD + settle-response print instructions.
 * Configures WHAT/WHEN prints; never the physical printer (browser limitation).
 */
class PosPrintSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function storeFixture(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function staff(Store $r, string $role = 'cashier'): StaffMember
    {
        return StaffMember::create([
            'owner_id' => $r->user_id, 'store_id' => $r->id,
            'name' => 'S '.Str::random(4), 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => $role, 'active' => 1,
        ]);
    }

    private function item(Store $r, float $price = 10): Menu
    {
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => 1]);

        return Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => $price, 'active' => 1]);
    }

    public function test_settings_saved_restaurant_level_and_read_back(): void
    {
        $r = $this->storeFixture();

        $this->actingAs($r->user, 'web')
            ->postJson(route('pos.settings.save', ['store' => $r->id]), [
                'auto_print' => 'both', 'copies' => 2, 'station_name' => 'Front', 'autolock_minutes' => 15,
            ])->assertOk()->assertJsonPath('settings.auto_print', 'both');

        $raw = StoreSettings::legacy((int) $r->id, 'pos_settings');
        $this->assertSame('both', json_decode($raw, true)['auto_print']);
        $this->assertSame(2, json_decode($raw, true)['copies']);
    }

    public function test_settings_saved_per_branch(): void
    {
        $r = $this->storeFixture();
        $branch = Branch::create(['store_id' => $r->id, 'name' => 'Downtown', 'active' => 1, 'position' => 1]);

        $this->actingAs($r->user, 'web')
            ->postJson(route('pos.settings.save', ['store' => $r->id]), [
                'branch_id' => $branch->id, 'auto_print' => 'kitchen', 'copies' => 1, 'station_name' => 'Line 1', 'autolock_minutes' => 10,
            ])->assertOk();

        $this->assertSame('kitchen', $branch->fresh()->pos_settings['auto_print']);
        $this->assertSame('Line 1', $branch->fresh()->pos_settings['station_name']);
    }

    public function test_non_manager_staff_cannot_edit_settings(): void
    {
        $r = $this->storeFixture();
        $cashier = $this->staff($r, 'cashier'); // has pos, not pos_shift_close

        $this->actingAs($cashier, 'staff')
            ->postJson(route('pos.settings.save', ['store' => $r->id]), [
                'auto_print' => 'both', 'copies' => 1, 'autolock_minutes' => 10,
            ])->assertStatus(403);
    }

    public function test_settle_response_carries_print_instructions(): void
    {
        $r = $this->storeFixture();
        $staff = $this->staff($r);
        $item = $this->item($r, 10);

        // Configure restaurant-level both/2 copies.
        StoreSettings::set($r->id, 'pos_settings', json_encode(['auto_print' => 'both', 'copies' => 2, 'station_name' => 'Bar', 'autolock_minutes' => 10]));

        $order = PosService::createOrder($r, $staff, ['type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 1]]]);

        $res = $this->actingAs($r->user, 'web')
            ->postJson(route('pos.orders.settle', ['id' => $order->id, 'store' => $r->id]), ['method' => 'cash', 'tendered' => 10])
            ->assertOk()
            ->assertJsonPath('print.auto', 'both')
            ->assertJsonPath('print.copies', 2)
            ->assertJsonPath('print.station', 'Bar');

        $this->assertNotNull($res->json('print.receipt_url'));
        $this->assertNotNull($res->json('print.kitchen_url'));
        $this->assertStringContainsString('format=kitchen', $res->json('print.kitchen_url'));
    }

    public function test_settle_print_off_by_default(): void
    {
        $r = $this->storeFixture();
        $staff = $this->staff($r);
        $item = $this->item($r, 10);
        $order = PosService::createOrder($r, $staff, ['type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 1]]]);

        $this->actingAs($r->user, 'web')
            ->postJson(route('pos.orders.settle', ['id' => $order->id, 'store' => $r->id]), ['method' => 'cash', 'tendered' => 10])
            ->assertOk()
            ->assertJsonPath('print.auto', 'off');
    }

    public function test_customer_display_localises_by_lang_param(): void
    {
        $r = $this->storeFixture();
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);

        $en = $this->actingAs($r->user, 'web')
            ->get(route('pos.display', ['store' => $r->id, 'lang' => 'en']))
            ->assertOk()->getContent();
        $this->assertStringContainsString('dir="ltr"', $en);

        $ar = $this->actingAs($r->user, 'web')
            ->get(route('pos.display', ['store' => $r->id, 'lang' => 'ar']))
            ->assertOk()->getContent();
        $this->assertStringContainsString('dir="rtl"', $ar);
    }

    public function test_receipt_localises_by_lang_param(): void
    {
        $r = $this->storeFixture();
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);
        $item = $this->item($r, 12);
        $order = OrderApiService::create($r->user_id, $r->id, [
            'type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 1]],
        ]);

        // Explicit ?lang drives the printout direction (and, with lang files, strings).
        $en = $this->actingAs($r->user, 'web')
            ->get(route('pos.orders.receipt', ['id' => $order->id, 'store' => $r->id, 'lang' => 'en']))
            ->assertOk()->getContent();
        $this->assertStringContainsString('dir="ltr"', $en);

        $ar = $this->actingAs($r->user, 'web')
            ->get(route('pos.orders.receipt', ['id' => $order->id, 'store' => $r->id, 'lang' => 'ar']))
            ->assertOk()->getContent();
        $this->assertStringContainsString('dir="rtl"', $ar);
    }
}
