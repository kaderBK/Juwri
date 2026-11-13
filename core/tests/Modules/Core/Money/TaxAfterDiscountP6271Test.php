<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Money;

use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StaffMember;
use App\Models\StoreOption;
use App\Services\PosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-271: the legacy (non VAT-engine) tax is charged on the subtotal after the order discount; an order placed
 * before the rule (`tax_pre_discount`) keeps its pre-discount tax and total.
 */
class TaxAfterDiscountP6271Test extends TestCase
{
    use RefreshDatabase;

    private function order(string $currency, array $opts, float $price, float $discount, ?bool $old = null): Order
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1'], 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $s = Store::create([
            'user_id' => $owner->id, 'slug' => 't-'.Str::lower(Str::random(8)), 'color' => '#111', 'title' => 'R', 'sub_title' => 'T',
            'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
        ]);
        foreach (['currency_code' => $currency] + $opts as $k => $v) {
            StoreOption::updateStoreOption($s->id, $k, $v);
        }
        $staff = StaffMember::create(['owner_id' => $s->user_id, 'store_id' => $s->id, 'name' => 'S', 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => 'manager', 'active' => 1]);
        $cat = MenuCategory::create(['store_id' => $s->id, 'name' => 'C', 'active' => 1]);
        $item = Menu::create(['store_id' => $s->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => $price, 'active' => 1]);
        $o = PosService::createOrder($s, $staff, ['type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 1]]]);
        $o->forceFill(['discount_amount' => $discount, 'tax_pre_discount' => $old])->save();

        return $o->fresh();
    }

    public static function golden(): array
    {
        return [
            // currency, opts, price, discount, [tax, grand] new rule, [tax, grand] an order placed before P6-271
            'SAR 15% 100 − 20' => ['SAR', ['restaurant_taxx_charge' => '15'], 100, 20, [12.0, 92.0], [15.0, 95.0]],
            'KWD 5% 10.500 − 1.250' => ['KWD', ['restaurant_taxx_charge' => '5'], 10.5, 1.25, [0.463, 9.713], [0.525, 9.775]],
            'BHD 10% 3.335 − 0.335' => ['BHD', ['restaurant_taxx_charge' => '10'], 3.335, 0.335, [0.3, 3.3], [0.334, 3.334]],
            'SAR fixed 2.00 tax' => ['SAR', ['restaurant_taxx_charge' => '2', 'restaurant_tax_type' => 'fixed'], 50, 10, [2.0, 42.0], [2.0, 42.0]],
            'SAR discount covers all' => ['SAR', ['restaurant_taxx_charge' => '15'], 30, 30, [0.0, 0.0], [4.5, 4.5]],
        ];
    }

    #[DataProvider('golden')]
    public function test_tax_after_discount_and_old_orders_unchanged(string $cur, array $opts, float $price, float $disc, array $new, array $old): void
    {
        $m = $this->order($cur, $opts, $price, $disc)->computeMoney();
        $this->assertSame($new, [$m['tax'], $m['grand']]);

        $o = $this->order($cur, $opts, $price, $disc, true);
        $m = $o->computeMoney();
        $this->assertSame($old, [$m['tax'], $m['grand']]);
        $this->assertSame($old[1], (float) $o->total);
    }

    public function test_migration_marks_existing_orders_old_rule(): void
    {
        $o = $this->order('SAR', ['restaurant_taxx_charge' => '15'], 100, 20);
        $this->assertNull($o->getAttribute('tax_pre_discount'));
        $migration = require base_path('app/Modules/Ordering/Database/Migrations/2026_10_19_120000_add_tax_pre_discount_to_orders.php');
        $migration->down();
        $migration->up(); // backfill: every order already placed keeps the pre-discount rule
        $o = $o->fresh();
        $this->assertTrue((bool) $o->tax_pre_discount);
        $this->assertSame(95.0, (float) $o->total);
    }
}
