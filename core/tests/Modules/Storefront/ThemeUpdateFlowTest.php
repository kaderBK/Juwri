<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use App\Models\Theme;
use App\Services\ThemePurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Services\WalletService;
use Juwri\Modules\Storefront\Models\ThemeSetting;
use Juwri\Modules\Storefront\Services\ThemeUpdateService;
use Tests\TestCase;

/**
 * P4-7: safe updates — merchants pin a version, newer published versions are
 * offered (never forced), moving preserves the versionless settings record,
 * and every move is entitlement-gated and published-only.
 */
class ThemeUpdateFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/marketplace-themes/updtest'));
        parent::tearDown();
    }

    private function makeThemeWithVersions(float $price = 0): Theme
    {
        foreach (['1.0.0', '2.0.0'] as $semver) {
            File::ensureDirectoryExists(storage_path("app/marketplace-themes/updtest/{$semver}/templates"));
            File::put(
                storage_path("app/marketplace-themes/updtest/{$semver}/templates/index.liquid"),
                "<h1 data-v=\"{$semver}\">{{ restaurant.title | escape }}</h1>"
            );
        }

        $theme = Theme::create(['name' => 'Upd Test', 'slug' => 'updtest', 'status' => 'published', 'price' => $price]);
        $theme->versions()->create(['semver' => '1.0.0', 'manifest' => [], 'checksum' => str_repeat('c', 64), 'status' => 'published']);
        $theme->versions()->create(['semver' => '2.0.0', 'manifest' => [], 'checksum' => str_repeat('d', 64), 'status' => 'published']);

        return $theme;
    }

    private function makeOwnerWithRestaurant(string $template): array
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'upd-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => 'Upd Shop', 'sub_title' => 'S', 'description' => 'D',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', $template);

        return [$owner, $store];
    }

    public function test_updates_are_offered_when_a_newer_published_version_exists(): void
    {
        $this->makeThemeWithVersions();
        [$owner, $store] = $this->makeOwnerWithRestaurant('mp:updtest@1.0.0');

        $updates = new ThemeUpdateService()->updatesAvailable($owner->id);

        $this->assertCount(1, $updates);
        $this->assertSame('1.0.0', $updates[0]['current']);
        $this->assertSame('2.0.0', $updates[0]['latest']);

        // Already on latest → nothing offered.
        StoreOption::updateStoreOption($store->id, 'store_template', 'mp:updtest@2.0.0');
        $this->assertSame([], new ThemeUpdateService()->updatesAvailable($owner->id));
    }

    public function test_update_moves_the_pin_and_settings_survive(): void
    {
        $this->makeThemeWithVersions();
        [$owner, $store] = $this->makeOwnerWithRestaurant('mp:updtest@1.0.0');

        // Merchant customization under the VERSIONLESS key (the P2-4 contract).
        ThemeSetting::create(['store_id' => $store->id, 'template' => 'mp:updtest', 'settings' => ['sections.hero.heading' => 'Mine']]);

        $this->actingAs($owner)->postJson('theme-updates/'.$store->id)
            ->assertOk()->assertJson(['template' => 'mp:updtest@2.0.0']);

        $this->assertSame('mp:updtest@2.0.0', store_options($store->id)->store_template);
        $this->assertSame('Mine', ThemeSetting::where('template', 'mp:updtest')->first()->settings['sections.hero.heading'], 'settings survived the move');

        // Explicit pin back to 1.0.0 (still published) works too.
        $this->actingAs($owner)->postJson('theme-updates/'.$store->id, ['version' => '1.0.0'])
            ->assertOk()->assertJson(['template' => 'mp:updtest@1.0.0']);
    }

    public function test_update_is_entitlement_gated_and_published_only(): void
    {
        $theme = $this->makeThemeWithVersions(price: 30);
        [$owner, $store] = $this->makeOwnerWithRestaurant('mp:updtest@1.0.0');

        // Paid theme, no license → 422.
        $this->actingAs($owner)->postJson('theme-updates/'.$store->id)->assertStatus(422);

        WalletService::credit($owner, 40, 'seed');
        new ThemePurchaseService()->purchase($owner, $theme);

        // Unpublished target → 422.
        $theme->versions()->create(['semver' => '3.0.0-beta', 'manifest' => [], 'checksum' => str_repeat('e', 64), 'status' => 'review']);
        $this->actingAs($owner)->postJson('theme-updates/'.$store->id, ['version' => '3.0.0-beta'])->assertStatus(422);

        // Licensed + published → moves.
        $this->actingAs($owner)->postJson('theme-updates/'.$store->id)
            ->assertOk()->assertJson(['template' => 'mp:updtest@2.0.0']);

        // Cross-tenant → 404.
        $stranger = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $this->actingAs($stranger)->postJson('theme-updates/'.$store->id)->assertNotFound();
    }

    public function test_owner_updates_endpoint_lists_their_restaurants(): void
    {
        $this->makeThemeWithVersions();
        [$owner] = $this->makeOwnerWithRestaurant('mp:updtest@1.0.0');

        $this->actingAs($owner)->getJson('theme-updates')
            ->assertOk()
            ->assertJsonPath('updates.0.latest', '2.0.0');
    }
}
