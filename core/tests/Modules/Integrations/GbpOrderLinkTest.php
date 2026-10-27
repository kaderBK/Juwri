<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Integrations\Services\Gbp\FakeGbpClient;
use Juwri\Modules\Storefront\Models\StoreDomain;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * google-partner Lane A / A6 — order action link uses the store's EFFECTIVE public URL
 * (reseller / custom-domain aware), not the raw platform slug route.
 */
class GbpOrderLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        config(['settings.admin_allow_gbp_link' => true]);
        FakeGbpClient::reset();
        // Production-shaped layout context (stdClass via ViewServiceProvider), not a
        // hand-rolled double — keeps ViewComposerContractTest's cap intact.
        $this->shareLayoutGlobals();
    }

    private function ownerRestaurant(): array
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

        return [$owner, $r];
    }

    private function verifiedDomain(Store $r, string $domain): void
    {
        StoreDomain::create([
            'store_id' => $r->id, 'domain' => $domain, 'verified' => true,
            'verification_token' => Str::random(20), 'ssl_status' => 'active',
        ]);
    }

    private function connectGbp(Store $r, string $loc = 'LOC-1'): void
    {
        Credential::create([
            'store_id' => $r->id, 'feature' => 'gbp', 'provider' => 'gbp', 'active' => true,
            'secrets' => ['access_token' => 'AT', 'location_id' => $loc],
        ]);
    }

    public function test_effective_public_url_prefers_verified_custom_domain(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $this->verifiedDomain($r, 'order.acme.example');
        $this->assertSame('https://order.acme.example', effective_public_url($r));
    }

    public function test_effective_public_url_falls_back_to_slug_route(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $this->assertSame(route('publicView', $r->slug), effective_public_url($r));
    }

    public function test_set_link_uses_custom_domain(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $this->verifiedDomain($r, 'order.acme.example');
        $this->connectGbp($r, 'LOC-1');
        $this->withoutMiddleware();

        $this->actingAs($owner)->post(route('stores.gbp.setLink').'?store_id='.$r->id)->assertRedirect();

        $link = FakeGbpClient::$links['LOC-1'][0];
        $this->assertSame('https://order.acme.example', $link['uri']);
        $this->assertTrue($link['isPreferred']);
        $this->assertSame('FOOD_ORDERING', $link['placeActionType']);
    }

    public function test_select_location_auto_sets_link_to_effective_url(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $this->verifiedDomain($r, 'shop.brand.example');
        // OAuth-connected, not yet linked.
        Credential::create([
            'store_id' => $r->id, 'feature' => 'gbp', 'provider' => 'gbp', 'active' => true,
            'secrets' => ['access_token' => 'AT'],
        ]);
        $this->withoutMiddleware();

        $this->actingAs($owner)->post(route('stores.gbp.location').'?store_id='.$r->id, [
            'location_id' => 'accounts/111/locations/222',
        ])->assertRedirect();

        $link = FakeGbpClient::$links['accounts/111/locations/222'][0];
        $this->assertSame('https://shop.brand.example', $link['uri']);
        $this->assertTrue($link['isPreferred']);
    }
}
