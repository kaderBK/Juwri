<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Http\Middleware\Installed;
use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\TestCase;

class WalletPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(Installed::class);
    }

    private function storeFixture(array $options = []): Store
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $user->id, 'slug' => Str::slug('r'.Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($options as $k => $v) {
            StoreOption::updateStoreOption($store->id, $k, $v);
        }

        return $store;
    }

    public function test_config_disabled_without_publishable_key(): void
    {
        $store = $this->storeFixture();
        $this->getJson("/{$store->slug}/wallet/config")
            ->assertOk()->assertJsonPath('enabled', false);
    }

    public function test_config_enabled_with_publishable_key(): void
    {
        $store = $this->storeFixture(['restaurant_stripe_publishable_key' => 'pk_test_abc', 'currency_code' => 'AED']);
        $this->getJson("/{$store->slug}/wallet/config")
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('publishable_key', 'pk_test_abc')
            ->assertJsonPath('currency', 'aed');
    }

    public function test_intent_404_for_unknown_order(): void
    {
        $store = $this->storeFixture(['restaurant_stripe_secret_key' => 'sk_test_x']);
        $this->postJson("/{$store->slug}/wallet/intent", ['order_id' => 999])
            ->assertStatus(404);
    }

    public function test_intent_404_for_another_restaurants_order(): void
    {
        $store = $this->storeFixture(['restaurant_stripe_secret_key' => 'sk_test_x']);
        $other = $this->storeFixture();
        $foreign = Order::create(['store_id' => $other->id, 'status' => 'new', 'type' => 'takeaway']);

        $this->postJson("/{$store->slug}/wallet/intent", ['order_id' => $foreign->id])
            ->assertStatus(404);
    }

    public function test_intent_503_when_stripe_not_configured(): void
    {
        $store = $this->storeFixture(); // no secret key
        $order = Order::create(['store_id' => $store->id, 'status' => 'new', 'type' => 'takeaway']);

        $this->postJson("/{$store->slug}/wallet/intent", ['order_id' => $order->id])
            ->assertStatus(503);
    }

    public function test_intent_409_when_already_paid(): void
    {
        $store = $this->storeFixture(['restaurant_stripe_secret_key' => 'sk_test_x']);
        $order = Order::create(['store_id' => $store->id, 'status' => 'completed', 'type' => 'takeaway', 'is_paid' => 1]);

        $this->postJson("/{$store->slug}/wallet/intent", ['order_id' => $order->id])
            ->assertStatus(409);
    }

    public function test_apple_pay_domain_association_404_when_absent(): void
    {
        $this->get('/.well-known/apple-developer-merchantid-domain-association')
            ->assertStatus(404);
    }
}
