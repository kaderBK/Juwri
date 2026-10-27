<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Channels\Models\PosItemMap;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GAPS-RUN-2 B1-P4: POS bridge owner UI (owner UI) — connect, item mapping, push log.
 */
class PosBridgeOwnerUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        config(['settings.admin_allow_pos_bridge' => true]);
        $settings = new class(['theme_color' => '#111', 'site_title' => 'T', 'meta_description' => 'T', 'social_share_image' => 'd.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'f.png'])
        {
            public function __construct(private array $d) {}

            public function __get($k): mixed
            {
                return $this->d[$k] ?? null;
            }

            public function __set($k, $v): void
            {
                $this->d[$k] = $v;
            }

            public function __isset($k): bool
            {
                return array_key_exists($k, $this->d);
            }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect()]);
        $this->withoutMiddleware();
    }

    private function ownerRestaurant(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$owner, $r];
    }

    public function test_index_renders_with_nav_entry(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $res = $this->actingAs($owner)->get(route('stores.posbridge.index').'?store_id='.$r->id)->assertOk();
        $res->assertSee(___('Connect a POS'));            // page rendered
        $res->assertSeeHtml('pos-bridge');        // nav entry (link URL present)
    }

    public function test_connect_creates_connection_and_encrypted_credential(): void
    {
        [$owner, $r] = $this->ownerRestaurant();

        $this->actingAs($owner)->post(route('stores.posbridge.connect').'?store_id='.$r->id, [
            'provider' => 'square', 'external_location_id' => 'LOC9', 'access_token' => 'tok_secret',
        ])->assertRedirect();

        $this->assertDatabaseHas('pos_bridge_connections', ['provider' => 'square', 'store_id' => $r->id, 'external_location_id' => 'LOC9']);
        $cred = Credential::for('square', $r->id);
        $this->assertSame('tok_secret', $cred['access_token']);
    }

    public function test_save_mapping_persists_and_can_clear(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $menu = Menu::create(['store_id' => $r->id, 'name' => 'Latte', 'price' => 4, 'active' => 1]);

        $this->actingAs($owner)->post(route('stores.posbridge.mapping').'?store_id='.$r->id, [
            'provider' => 'square', 'menu_id' => $menu->id, 'external_id' => 'SQ_X',
        ])->assertRedirect();
        $this->assertSame('SQ_X', PosItemMap::externalIdFor('square', $menu->id));

        // Empty external_id clears the mapping.
        $this->actingAs($owner)->post(route('stores.posbridge.mapping').'?store_id='.$r->id, [
            'provider' => 'square', 'menu_id' => $menu->id, 'external_id' => '',
        ])->assertRedirect();
        $this->assertNull(PosItemMap::externalIdFor('square', $menu->id));
    }

    public function test_index_is_tenant_scoped(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        [$other, $otherR] = $this->ownerRestaurant();

        // Owner cannot view another tenant's restaurant id.
        $this->actingAs($owner)->get(route('stores.posbridge.index').'?store_id='.$otherR->id)->assertForbidden();
    }

    public function test_mapping_rejects_cross_tenant_menu(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        [$other, $otherR] = $this->ownerRestaurant();
        $foreignMenu = Menu::create(['store_id' => $otherR->id, 'name' => 'X', 'price' => 1, 'active' => 1]);

        $this->actingAs($owner)->post(route('stores.posbridge.mapping').'?store_id='.$r->id, [
            'provider' => 'square', 'menu_id' => $foreignMenu->id, 'external_id' => 'SQ_Y',
        ])->assertForbidden();
    }
}
