<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use Juwri\Core\Models\Language;
use App\Models\MenuBanner;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

class BannerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->withoutMiddleware();
    }

    private function makeUser(): User
    {
        $plan = TestPlans::create([
            'name' => 'P ' . Str::random(6), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999]), 'status' => 1,
        ]);
        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function makeRestaurant(User $owner): Store
    {
        $title = 'R ' . Str::random(6);
        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title) . '-' . Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_store_creates_banner(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);

        $this->actingAs($owner)->post(route('banners.store'), [
            'store_id' => $r->id, 'image' => 'b1.webp', 'title' => 'Promo', 'active' => 1,
        ]);

        $banner = MenuBanner::where('store_id', $r->id)->first();
        $this->assertSame('b1.webp', $banner->image);
        $this->assertSame('Promo', $banner->title);
        $this->assertTrue($banner->active);
    }

    public function test_store_requires_image(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);

        $this->actingAs($owner)->post(route('banners.store'), [
            'store_id' => $r->id, 'title' => 'No image',
        ])->assertSessionHasErrors('image');

        $this->assertEquals(0, MenuBanner::where('store_id', $r->id)->count());
    }

    public function test_update_and_toggle_active(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $banner = MenuBanner::create(['store_id' => $r->id, 'image' => 'b.webp', 'title' => 'Old', 'active' => true, 'position' => 1]);

        // No 'active' field => becomes hidden.
        $this->actingAs($owner)->putJson(route('banners.update', $banner->id), [
            'image' => 'b.webp', 'title' => 'New',
        ])->assertJson(['success' => true]);

        $banner->refresh();
        $this->assertSame('New', $banner->title);
        $this->assertFalse($banner->active);
    }

    public function test_destroy(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $banner = MenuBanner::create(['store_id' => $r->id, 'image' => 'b.webp', 'position' => 1]);

        $this->actingAs($owner)->deleteJson(route('banners.destroy', $banner->id))->assertJson(['success' => true]);
        $this->assertDatabaseMissing('menu_banners', ['id' => $banner->id]);
    }

    public function test_reorder(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $a = MenuBanner::create(['store_id' => $r->id, 'image' => 'a.webp', 'position' => 0]);
        $b = MenuBanner::create(['store_id' => $r->id, 'image' => 'b.webp', 'position' => 1]);

        $this->actingAs($owner)->postJson(route('banners.reorder'), ['position' => [$b->id, $a->id]])
            ->assertJson(['success' => true]);

        $this->assertEquals(0, $b->fresh()->position);
        $this->assertEquals(1, $a->fresh()->position);
    }

    public function test_tenant_isolation(): void
    {
        $owner = $this->makeUser();
        $otherRestaurant = $this->makeRestaurant($this->makeUser());
        $otherBanner = MenuBanner::create(['store_id' => $otherRestaurant->id, 'image' => 'x.webp', 'position' => 0]);

        $this->actingAs($owner)->post(route('banners.store'), [
            'store_id' => $otherRestaurant->id, 'image' => 'hack.webp',
        ])->assertForbidden();

        $this->actingAs($owner)->deleteJson(route('banners.destroy', $otherBanner->id))->assertForbidden();
        $this->assertDatabaseHas('menu_banners', ['id' => $otherBanner->id]);
    }
}
