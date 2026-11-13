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
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * google-partner Lane A / A4 — location picker state machine + selectLocation.
 */
class GbpLocationPickerTest extends TestCase
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

    private function oauthConnect(Store $r): void
    {
        Credential::create([
            'store_id' => $r->id, 'feature' => 'gbp', 'provider' => 'gbp', 'active' => true,
            'secrets' => ['access_token' => 'AT', 'refresh_token' => 'RT', 'expires_at' => time() + 3600],
        ]);
    }

    public function test_disconnected_shows_connect_button(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $this->withoutMiddleware();
        $this->actingAs($owner)->get(route('stores.gbp.index').'?store_id='.$r->id)
            ->assertOk()->assertSee('Connect Google');
    }

    public function test_connected_no_location_shows_picker_from_fake(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $this->oauthConnect($r);
        $this->withoutMiddleware();
        // Fake driver seeds "Demo Location" under accounts/111.
        $this->actingAs($owner)->get(route('stores.gbp.index').'?store_id='.$r->id)
            ->assertOk()
            ->assertSee('choose a location')
            ->assertSee('Demo Location');
    }

    public function test_select_location_stores_and_derives_account(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $this->oauthConnect($r);
        $this->withoutMiddleware();

        $this->actingAs($owner)->post(route('stores.gbp.location').'?store_id='.$r->id, [
            'location_id' => 'accounts/111/locations/222',
        ])->assertRedirect();

        $creds = Credential::for('gbp', $r->id);
        $this->assertSame('accounts/111/locations/222', $creds['location_id']);
        $this->assertSame('accounts/111', $creds['account_id']); // derived server-side
        // OAuth token preserved through the merge.
        $this->assertSame('AT', $creds['access_token']);
    }

    public function test_select_location_auto_sets_ordering_link(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $this->oauthConnect($r);
        $this->withoutMiddleware();

        $this->actingAs($owner)->post(route('stores.gbp.location').'?store_id='.$r->id, [
            'location_id' => 'accounts/111/locations/222',
        ])->assertRedirect();

        // Fake client recorded a preferred FOOD_ORDERING link on the linked location.
        $this->assertArrayHasKey('accounts/111/locations/222', FakeGbpClient::$links);
        $link = FakeGbpClient::$links['accounts/111/locations/222'][0];
        $this->assertSame('FOOD_ORDERING', $link['placeActionType']);
        $this->assertTrue($link['isPreferred']);
    }

    public function test_linked_state_renders(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        Credential::create([
            'store_id' => $r->id, 'feature' => 'gbp', 'provider' => 'gbp', 'active' => true,
            'secrets' => ['access_token' => 'AT', 'location_id' => 'accounts/111/locations/222'],
        ]);
        $this->withoutMiddleware();
        $this->actingAs($owner)->get(route('stores.gbp.index').'?store_id='.$r->id)
            ->assertOk()->assertSee('Linked location')->assertSee('accounts/111/locations/222');
    }

    public function test_select_location_tenant_scoped(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        [$other, $otherR] = $this->ownerRestaurant();
        $this->withoutMiddleware();
        $this->actingAs($owner)->post(route('stores.gbp.location').'?store_id='.$otherR->id, [
            'location_id' => 'accounts/111/locations/222',
        ])->assertForbidden();
    }
}
