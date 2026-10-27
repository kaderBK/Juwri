<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\PlatformSettings;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * WI-M8 — the anonymous /api/v1/menu/{id} endpoint must answer exactly what the web
 * storefront answers: 404 for a suspended owner or an inactive plan. Before the fix it
 * served the full menu regardless, so a suspended/lapsed merchant kept a working public
 * ordering surface for the customer app and the AI integrations — a commercial bypass.
 */
class PublicMenuSuspensionGateTest extends TestCase
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

    /** @return array{0: User, 1: Plan, 2: Store} */
    private function activeStore(): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]), 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => 1, 'position' => 1]);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 10, 'active' => 1]);

        return [$owner, $plan, $store];
    }

    public function test_a_suspended_owner_gets_404_from_the_public_menu_api(): void
    {
        [$owner, , $store] = $this->activeStore();

        $this->getJson('/api/v1/menu/'.$store->id)->assertOk(); // control

        $owner->update(['status' => 0]);
        $this->getJson('/api/v1/menu/'.$store->id)->assertNotFound();
    }

    public function test_an_inactive_plan_gets_404_from_the_public_menu_api(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        [, $plan, $store] = $this->activeStore();

        $plan->update(['status' => 0]);
        $this->getJson('/api/v1/menu/'.$store->id)->assertNotFound();
    }

    public function test_a_suspended_store_is_not_served_a_304_either(): void
    {
        [$owner, , $store] = $this->activeStore();

        $etag = $this->getJson('/api/v1/menu/'.$store->id)->assertOk()->headers->get('ETag');
        $this->assertNotNull($etag);

        $owner->update(['status' => 0]);

        // Ordering matters: the gate must precede the If-None-Match branch, or a suspended store
        // still 304s to a client holding the pre-suspension ETag.
        $this->withHeaders(['If-None-Match' => $etag])
            ->getJson('/api/v1/menu/'.$store->id)
            ->assertNotFound();
    }

    public function test_order_tracking_is_not_gated_by_suspension(): void
    {
        [$owner, , $store] = $this->activeStore();
        $order = Order::create([
            'store_id' => $store->id, 'track_token' => Str::random(16),
            'status' => 'pending', 'type' => 'takeaway', 'created_at' => now(),
        ]);

        $owner->update(['status' => 0]);

        // A diner who already placed an order must keep tracking it even after the merchant lapses.
        $this->getJson('/api/v1/track/'.$order->track_token)->assertOk();
    }

    public function test_the_platform_kill_switch_restores_the_pre_fix_behaviour(): void
    {
        [$owner, , $store] = $this->activeStore();
        $owner->update(['status' => 0]);

        PlatformSettings::set('api_public_menu_gate', '0');
        config(['settings.api_public_menu_gate' => '0']);
        $this->getJson('/api/v1/menu/'.$store->id)->assertOk();

        PlatformSettings::set('api_public_menu_gate', '1');
        config(['settings.api_public_menu_gate' => '1']);
        $this->getJson('/api/v1/menu/'.$store->id)->assertNotFound();
    }

    public function test_the_gate_defaults_on_when_the_option_row_is_absent(): void
    {
        [$owner, , $store] = $this->activeStore();
        // No api_public_menu_gate option/config set at all.
        $owner->update(['status' => 0]);

        $this->getJson('/api/v1/menu/'.$store->id)->assertNotFound();
    }
}
