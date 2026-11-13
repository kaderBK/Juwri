<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Models\MenuCategory;
use App\Models\StaffMember;
use App\Models\StoreOption;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

class PosTaxPreviewParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_pos_preview_uses_minor_unit_percentage_rounding(): void
    {
        $script = file_get_contents(base_path('../assets/pos/pos-core.js'));

        $this->assertStringContainsString(
            'return Math.round(Math.round(base * f) * charge / 100) / f;',
            $script,
            'The browser preview must avoid the 14.50 × 15% = 2.17 floating-point path.'
        );
    }

    public function test_authoritative_pos_order_rounds_half_cent_tax_up(): void
    {
        $plan = TestPlans::create([
            'name' => 'POS parity '.Str::random(5),
            'description' => 'T',
            'monthly_price' => 1,
            'annual_price' => 1,
            'lifetime_price' => 1,
            'settings' => [
                'restaurant_limit' => 999,
                'menu_limit' => 999,
                'allow_ordering' => '1',
                'pos_enabled' => '1',
            ],
            'status' => 1,
        ]);
        $owner = User::factory()->create([
            'group_id' => (string) $plan->id,
            'status' => 1,
            'email_verified_at' => now(),
        ]);
        $store = Store::create([
            'user_id' => $owner->id,
            'slug' => 'pos-tax-parity-'.Str::lower(Str::random(5)),
            'color' => '#111',
            'title' => 'POS Tax Parity',
            'sub_title' => 'T',
            'description' => 'T',
            'main_image' => 'd.png',
            'cover_image' => 'd.png',
            'address' => '1 St',
            'phone' => '1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'restaurant_taxx_charge', 15);
        StoreOption::updateStoreOption($store->id, 'restaurant_tax_type', 'percentage');

        $category = MenuCategory::create([
            'store_id' => $store->id,
            'name' => 'Mains',
            'active' => 1,
        ]);
        $item = Menu::create([
            'store_id' => $store->id,
            'category_id' => $category->id,
            'name' => 'Half-cent tax item',
            'price' => 14.50,
            'active' => 1,
        ]);
        $staff = StaffMember::create([
            'owner_id' => $owner->id,
            'store_id' => $store->id,
            'name' => 'Cashier',
            'email' => Str::random(6).'@example.test',
            'password' => bcrypt('x'),
            'role' => 'cashier',
            'active' => 1,
        ]);

        $order = PosService::createOrder($store, $staff, [
            'type' => 'takeaway',
            'items' => [['item_id' => $item->id, 'quantity' => 1]],
        ])->fresh();

        $this->assertSame(14.50, (float) $order->subtotal);
        $this->assertSame(2.18, (float) $order->tax_total);
        $this->assertSame(16.68, (float) $order->grand_total);
        $this->assertSame(16.68, (float) $order->total);
    }
}
