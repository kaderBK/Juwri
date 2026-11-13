<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Integrations\Services\Gbp\FakeGbpClient;
use Juwri\Modules\Integrations\Services\Gbp\GbpClient;
use Juwri\Modules\Integrations\Services\Gbp\GbpClientFactory;
use Juwri\Modules\Integrations\Services\Gbp\GbpClientInterface;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * google-partner Lane A / A3 — real GbpClient + Fake parity + factory routing.
 * Real client is exercised with Http::fake only; never a live Google call.
 */
class GbpClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        FakeGbpClient::reset();
    }

    // ---- Fake parity: every interface method is implemented + round-trips ----

    public function test_fake_implements_full_interface(): void
    {
        $fake = new FakeGbpClient;
        $this->assertInstanceOf(GbpClientInterface::class, $fake);

        $accounts = $fake->listAccounts();
        $this->assertNotEmpty($accounts);
        $this->assertArrayHasKey('name', $accounts[0]);

        $locations = $fake->listLocations($accounts[0]['name']);
        $this->assertNotEmpty($locations);
        $this->assertArrayHasKey('title', $locations[0]);

        $this->assertSame([], $fake->getFoodMenus('222'));
        $updated = $fake->updateFoodMenus('222', ['menus' => [['sections' => []]]]);
        $this->assertArrayHasKey('menus', $updated);
        $this->assertSame($updated, $fake->getFoodMenus('222'));
    }

    // ---- Real client hits the right endpoints with a bearer ----

    private function realClient(): GbpClient
    {
        return new GbpClient(
            ['access_token' => 'TOKEN-XYZ', 'account_id' => 'accounts/111', 'location_id' => '222'],
            null // no storeId → uses creds access_token directly (no DB/refresh)
        );
    }

    public function test_real_list_accounts_calls_account_mgmt_api(): void
    {
        Http::fake([
            'mybusinessaccountmanagement.googleapis.com/*' => Http::response([
                'accounts' => [['name' => 'accounts/111', 'accountName' => 'Acme']],
            ], 200),
        ]);

        $accounts = $this->realClient()->listAccounts();
        $this->assertSame('accounts/111', $accounts[0]['name']);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'mybusinessaccountmanagement.googleapis.com/v1/accounts')
            && $req->hasHeader('Authorization', 'Bearer TOKEN-XYZ'));
    }

    public function test_real_list_locations_calls_business_info_api(): void
    {
        Http::fake([
            'mybusinessbusinessinformation.googleapis.com/*' => Http::response([
                'locations' => [['name' => 'locations/222', 'title' => 'Shop']],
            ], 200),
        ]);

        $locations = $this->realClient()->listLocations('accounts/111');
        $this->assertSame('Shop', $locations[0]['title']);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'mybusinessbusinessinformation.googleapis.com/v1/accounts/111/locations')
            && str_contains($req->url(), 'readMask='));
    }

    public function test_real_update_food_menus_patches_v4_with_update_mask(): void
    {
        Http::fake([
            'mybusiness.googleapis.com/v4/*' => Http::response(['name' => 'accounts/111/locations/222/foodMenus', 'menus' => []], 200),
        ]);

        $body = ['menus' => [['sections' => [['items' => []]]]]];
        $res = $this->realClient()->updateFoodMenus('222', $body);
        $this->assertArrayHasKey('name', $res);

        Http::assertSent(function ($req) {
            return $req->method() === 'PATCH'
                && str_contains($req->url(), 'mybusiness.googleapis.com/v4/accounts/111/locations/222/foodMenus')
                && str_contains($req->url(), 'updateMask=menus')
                && $req->hasHeader('Authorization', 'Bearer TOKEN-XYZ');
        });
    }

    public function test_real_update_food_menus_throws_on_failure(): void
    {
        Http::fake(['mybusiness.googleapis.com/v4/*' => Http::response('nope', 500)]);
        $this->expectException(\RuntimeException::class);
        $this->realClient()->updateFoodMenus('222', ['menus' => []]);
    }

    public function test_real_client_dark_without_token(): void
    {
        // No access_token, no storeId → token() null → reads fail soft to [].
        $client = new GbpClient([], null);
        Http::fake();
        $this->assertSame([], $client->listAccounts());
        Http::assertNothingSent();
    }

    // ---- Factory routing ----

    private function connectedRestaurant(string $token = 'AT'): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($token !== '') {
            Credential::create([
                'store_id' => $r->id, 'feature' => 'gbp', 'provider' => 'gbp', 'active' => true,
                'secrets' => ['access_token' => $token, 'expires_at' => time() + 3600, 'location_id' => '222'],
            ]);
        }

        return $r;
    }

    public function test_factory_returns_real_when_driver_real_and_token(): void
    {
        config(['services.gbp.driver' => 'real']);
        $r = $this->connectedRestaurant();
        $this->assertInstanceOf(GbpClient::class, GbpClientFactory::make($r->id));
    }

    public function test_factory_returns_fake_when_driver_fake(): void
    {
        config(['services.gbp.driver' => 'fake']);
        $r = $this->connectedRestaurant();
        $this->assertInstanceOf(FakeGbpClient::class, GbpClientFactory::make($r->id));
    }

    public function test_factory_returns_fake_when_no_token(): void
    {
        config(['services.gbp.driver' => 'real']);
        $r = $this->connectedRestaurant(''); // no credential
        $this->assertInstanceOf(FakeGbpClient::class, GbpClientFactory::make($r->id));
    }
}
