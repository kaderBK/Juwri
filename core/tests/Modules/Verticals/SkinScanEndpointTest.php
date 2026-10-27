<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Verticals;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Core\Models\Store;
use Juwri\Modules\Verticals\Models\SkinProfile;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * rev/multi-vertical — the lumiere (cosmetic) theme's "Scan Your Face" endpoints.
 *
 * scan() delegates to the bound SkinScanProvider (mock today) and returns flat
 * metrics the theme reads directly; profile() persists the routine/metrics to a
 * per-viewer skin_profiles row. These guard the JSON contract + the persistence,
 * so a future real provider swap can be checked against the same shape.
 */
class SkinScanEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function cosmeticStore(): Store
    {
        $owner = User::factory()->create();

        return Store::factory()->create(['user_id' => $owner->id, 'store_type' => 'cosmetic']);
    }

    public function test_scan_returns_the_flat_metric_payload(): void
    {
        $store = $this->cosmeticStore();
        $a = Menu::create(['store_id' => $store->id, 'name' => 'Serum', 'price' => 30, 'active' => 1]);
        $b = Menu::create(['store_id' => $store->id, 'name' => 'Cleanser', 'price' => 20, 'active' => 1]);

        $res = $this->postJson(route('skin.scan', ['slug' => $store->slug]), ['image' => 'data:image/png;base64,AAAA']);

        $res->assertOk();
        // Contract keys the theme reads directly.
        foreach (['skin_age', 'acne', 'oily', 'wrinkles', 'dryness', 'recommendation', 'recommended_menu_ids'] as $k) {
            $res->assertJsonStructure([$k]);
        }
        $json = $res->json();
        $this->assertIsInt($json['skin_age']);
        $this->assertIsString($json['recommendation']);
        $this->assertNotSame('', $json['recommendation']);
        // Product picks are REAL menu ids from THIS store's catalog.
        $this->assertNotEmpty($json['recommended_menu_ids']);
        foreach ($json['recommended_menu_ids'] as $id) {
            $this->assertContains($id, [$a->id, $b->id]);
        }
    }

    public function test_scan_is_deterministic_for_the_same_image(): void
    {
        $store = $this->cosmeticStore();
        Menu::create(['store_id' => $store->id, 'name' => 'Serum', 'price' => 30, 'active' => 1]);

        $first = $this->postJson(route('skin.scan', ['slug' => $store->slug]), ['image' => 'same-capture'])->json();
        $second = $this->postJson(route('skin.scan', ['slug' => $store->slug]), ['image' => 'same-capture'])->json();

        $this->assertSame($first['skin_age'], $second['skin_age']);
        $this->assertSame($first['acne'], $second['acne']);
    }

    public function test_profile_persists_the_viewers_routine_and_metrics(): void
    {
        $store = $this->cosmeticStore();

        $res = $this->postJson(route('skin.profile', ['slug' => $store->slug]), [
            'skinAge' => 29,
            'routine' => ['am' => 'cleanser', 'pm' => 'retinol'],
            'oily' => 40,
        ]);

        $res->assertOk()->assertJson(['ok' => true]);
        $profile = SkinProfile::where('store_id', $store->id)->first();
        $this->assertNotNull($profile);
        $this->assertSame(29, (int) $profile->skin_age);
        $this->assertSame('retinol', $profile->routine['pm'] ?? null);
        $this->assertEquals(['skinAge' => 29, 'oily' => 40], $profile->metrics); // P6-72: metrics allowlist, not the raw post
    }

    public function test_scan_404s_for_an_unknown_store(): void
    {
        $this->postJson(route('skin.scan', ['slug' => 'no-such-store']), ['image' => 'x'])->assertNotFound();
    }
}
