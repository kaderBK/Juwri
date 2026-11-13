<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Rules\ReservedSlug;
use Juwri\Modules\Storefront\Models\SlugRedirect;
use Tests\Support\TestPlans;
use Tests\TestCase;

class SeoReservedSlugTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
    }

    private function makeUser(): User
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(6), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]), 'status' => 1,
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function makeRestaurant(User $owner, array $attrs = []): Store
    {
        $title = $attrs['title'] ?? 'R '.Str::random(6);

        return Store::create(array_merge([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '1234567890',
        ], $attrs));
    }

    public function test_reserved_slugs_fail_validation_case_insensitively(): void
    {
        foreach (['en', 'AR', 'Hi', 'blog', 'STUDIO', 'sitemap.xml'] as $slug) {
            $v = Validator::make(['slug' => $slug], ['slug' => [new ReservedSlug]]);
            $this->assertTrue($v->fails(), "expected '$slug' to be rejected");
        }

        $v = Validator::make(['slug' => 'my-restaurant'], ['slug' => [new ReservedSlug]]);
        $this->assertFalse($v->fails());
    }

    public function test_saving_hook_renames_reserved_slug_from_auto_generation(): void
    {
        $owner = $this->makeUser();

        // Sluggable would generate "ar" from a post titled "Ar" — hook must divert it.
        $store = $this->makeRestaurant($owner, ['title' => 'Ar', 'slug' => 'ar']);

        $this->assertSame('ar-menu', $store->fresh()->slug);
    }

    public function test_saving_hook_uniquifies_when_diverted_slug_taken(): void
    {
        $owner = $this->makeUser();
        $this->makeRestaurant($owner, ['slug' => 'hi-menu']);
        $store = $this->makeRestaurant($owner, ['title' => 'Hi', 'slug' => 'hi']);

        $this->assertSame('hi-menu-2', $store->fresh()->slug);
    }

    public function test_old_slug_redirects_permanently_to_renamed_post(): void
    {
        $owner = $this->makeUser();
        $store = $this->makeRestaurant($owner, ['slug' => 'ru-menu']);
        SlugRedirect::create(['old_slug' => 'ru', 'store_id' => $store->id]);

        $response = $this->get('/ru');

        $response->assertStatus(301);
        $response->assertRedirect(route('publicView', 'ru-menu'));
    }

    public function test_unknown_slug_still_404s(): void
    {
        $this->get('/no-such-place-'.Str::lower(Str::random(6)))->assertStatus(404);
    }
}
