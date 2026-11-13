<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use App\Models\Order;
use App\Models\OrderItem;
use App\Services\FeatureAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\EntitlementOverride;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Integrations\Models\ApiToken;
use Juwri\Modules\Menu\Models\Menu;
use Tests\TestCase;

/**
 * F0-7 (integrations pack): owner-API responses follow the shape Zapier/Make
 * review requires — mutations (the actions integrators call) return the FULL
 * object, never a {success:true}/{id,status} stub. (Owner-API searches returning
 * 200 [] is separately covered by the package-side searches; the owner API has no
 * search endpoint that 404s on empty.)
 */
class OwnerApiContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_order_status_returns_the_full_order(): void
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $raw = Str::random(40);
        ApiToken::create(['user_id' => $owner->id, 'name' => 't', 'token' => hash('sha256', $raw), 'abilities' => ['write']]);

        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $order = Order::create(['store_id' => $store->id, 'type' => 'takeaway', 'status' => 'new', 'customer_name' => 'Sam', 'track_token' => Str::random(24), 'created_at' => now()]);
        $menu = Menu::create(['store_id' => $store->id, 'name' => 'Tea', 'price' => 5, 'active' => '1']);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 2, 'unit_price' => 5]);

        $res = $this->putJson(
            "/api/v1/stores/{$store->id}/orders/{$order->id}/status",
            ['status' => 'preparing'],
            ['Authorization' => 'Bearer '.$raw]
        )->assertOk();

        // Full order row, not an {id, status} stub.
        $res->assertJsonPath('status', 'preparing')
            ->assertJsonPath('id', $order->id)
            ->assertJsonPath('customer_name', 'Sam')
            ->assertJsonStructure(['id', 'status', 'type', 'table_number', 'customer_name', 'total', 'currency', 'is_paid', 'created_at']);
    }

    /**
     * B4-a: the owner-API create-customer endpoint (the Zapier/Make/n8n "Create
     * Customer" action target) returns the full object, only accepts safe fields,
     * and is gated on customers:write + crm_suite.
     */
    public function test_create_customer_returns_full_object_and_is_gated(): void
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        config(['settings.admin_allow_crm_suite' => true]);
        EntitlementOverride::create(['user_id' => $owner->id, 'feature_key' => 'crm_suite', 'value' => '1', 'source_type' => 'admin']);
        FeatureAccess::flush();

        $write = Str::random(40);
        ApiToken::create(['user_id' => $owner->id, 'name' => 't', 'token' => hash('sha256', $write), 'abilities' => ['customers:write']]);
        $url = "/api/v1/stores/{$store->id}/customers";

        // Full object back, safe fields only (password/oauth ignored even if posted).
        $res = $this->postJson($url, [
            'name' => 'Dana', 'phone' => '555-1', 'email' => 'dana@example.com',
            'tags' => ['vip'], 'password' => 'hax', 'oauth_provider' => 'evil',
        ], ['Authorization' => 'Bearer '.$write])->assertStatus(201);
        $res->assertJsonPath('name', 'Dana')->assertJsonPath('tags', ['vip'])
            ->assertJsonStructure(['id', 'name', 'phone', 'email', 'orders_count', 'total_spent', 'tags', 'created_at']);
        $this->assertDatabaseHas('customers', ['store_id' => $store->id, 'name' => 'Dana', 'password' => null]);

        // name is required.
        $this->postJson($url, ['phone' => 'x'], ['Authorization' => 'Bearer '.$write])->assertStatus(422);

        // A customers:read-only token cannot create.
        $read = Str::random(40);
        ApiToken::create(['user_id' => $owner->id, 'name' => 'r', 'token' => hash('sha256', $read), 'abilities' => ['customers:read']]);
        $this->postJson($url, ['name' => 'No'], ['Authorization' => 'Bearer '.$read])->assertStatus(403);
    }
}
