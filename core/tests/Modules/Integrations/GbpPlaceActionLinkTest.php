<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Integrations\Services\Gbp\FakeGbpClient;
use Juwri\Modules\Integrations\Services\Gbp\PlaceActionLinkService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GAPS-RUN-2 B4-P1: GBP place-action-link service.
 */
class GbpPlaceActionLinkTest extends TestCase
{
    use RefreshDatabase;

    private PlaceActionLinkService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        FakeGbpClient::reset();
        config(['settings.admin_allow_gbp_link' => true]);
        $this->svc = new PlaceActionLinkService;
    }

    private function storeFixture(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function connect(Store $r, string $loc = 'LOC-1'): void
    {
        Credential::create([
            'store_id' => $r->id, 'feature' => 'gbp', 'provider' => 'gbp', 'active' => true,
            'secrets' => ['location_id' => $loc, 'access_token' => 'tok'],
        ]);
    }

    public function test_set_link_creates_preferred_food_ordering(): void
    {
        $r = $this->storeFixture();
        $this->connect($r);

        $link = $this->svc->setOrderingLink($r->id, 'https://shop.example/r');

        $this->assertSame('FOOD_ORDERING', $link['placeActionType']);
        $this->assertTrue($link['isPreferred']);
        $this->assertSame('https://shop.example/r', $link['uri']);
        $this->assertCount(1, FakeGbpClient::$links['LOC-1']);
    }

    public function test_re_set_patches_not_duplicates(): void
    {
        $r = $this->storeFixture();
        $this->connect($r);

        $this->svc->setOrderingLink($r->id, 'https://shop.example/a');
        $this->svc->setOrderingLink($r->id, 'https://shop.example/b');

        $this->assertCount(1, FakeGbpClient::$links['LOC-1'], 'must patch, not duplicate');
        $this->assertSame('https://shop.example/b', FakeGbpClient::$links['LOC-1'][0]['uri']);
    }

    public function test_requires_connection(): void
    {
        $r = $this->storeFixture(); // no credential
        $this->expectException(ValidationException::class);
        $this->svc->setOrderingLink($r->id, 'https://shop.example/r');
    }

    public function test_refuses_when_gate_off(): void
    {
        $r = $this->storeFixture();
        $this->connect($r);
        config(['settings.admin_allow_gbp_link' => false]);

        $this->expectException(ValidationException::class);
        $this->svc->setOrderingLink($r->id, 'https://shop.example/r');
    }
}
