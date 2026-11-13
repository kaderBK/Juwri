<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use App\Models\Order;
use App\Models\OrderItem;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Ordering\Services\Orders\OrderTextRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * OrderTextRenderer builds the kitchen/WhatsApp order text at checkout. Its
 * variant-option labels must resolve in one batched query, not a find() per option
 * per line.
 */
class OrderTextRendererTest extends TestCase
{
    use RefreshDatabase;

    public function test_render_batches_variant_option_lookups_and_shows_labels(): void
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'R',
            'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
        ]);

        $order = Order::create([
            'store_id' => $r->id, 'status' => 'completed', 'type' => 'takeaway',
            'customer_name' => 'C', 'phone_number' => '1', 'created_at' => now(),
        ]);
        // Two lines, each a variant selecting the first option ("Small") of a group.
        for ($n = 0; $n < 2; $n++) {
            $item = Menu::create(['store_id' => $r->id, 'name' => 'Dish'.$n, 'price' => 10, 'active' => 1]);
            $group = MenuVariantOption::create(['store_id' => $r->id, 'menu_id' => $item->id, 'title' => 'Size', 'options' => ['Small', 'Large']]);
            $variant = MenuVariant::create(['menu_id' => $item->id, 'price' => 12, 'options' => [$group->id => 0]]);
            OrderItem::create(['order_id' => $order->id, 'item_id' => $item->id, 'variation' => $variant->id, 'quantity' => 1]);
        }
        $order->load(['items.menu', 'items.variant', 'items.itemExtras.extra']);

        $optionQueries = 0;
        DB::listen(function ($q) use (&$optionQueries) {
            if (stripos($q->sql, 'modifier_groups') !== false) {
                $optionQueries++;
            }
        });

        $result = OrderTextRenderer::render($order);

        $this->assertLessThanOrEqual(1, $optionQueries, "variant-option lookups must batch into one query; got {$optionQueries}");
        $this->assertStringContainsString('Small', $result['whatsapp'], 'the resolved option label is rendered');
    }
}
