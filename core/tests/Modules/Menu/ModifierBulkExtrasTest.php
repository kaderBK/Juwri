<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use Juwri\Modules\Auth\Http\Middleware\Authenticate;
use Juwri\Modules\Auth\Http\Middleware\CheckUserIsBanned;
use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use Juwri\Core\Http\Middleware\Locale;
use Juwri\Core\Models\Language;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Bulk "Global extras" builder: create many reusable extras at once, plus the
 * AI title-suggestion endpoint's safe fallback.
 */
class ModifierBulkExtrasTest extends TestCase
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
        $this->withoutMiddleware([
            Authenticate::class, EnsureStaffDashboardPermission::class,
            Installed::class, CheckUserIsBanned::class, Locale::class,
        ]);
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
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);
    }

    public function test_bulk_store_creates_global_extras(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);

        $this->actingAs($owner)->postJson(route('modifiers.extras.bulkStore'), [
            'store_id' => $r->id,
            'items' => [
                ['title' => 'Extra Cheese', 'price' => 1.5, 'image' => 'a.webp'],
                ['title' => 'Bacon', 'price' => 2, 'image' => 'b.webp'],
                ['title' => 'No Image Sauce', 'price' => 0, 'image' => ''],
            ],
        ])->assertJson(['success' => true, 'created' => 3]);

        $extras = MenuExtra::where('store_id', $r->id)->where('is_global', true)->orderBy('id')->get();
        $this->assertCount(3, $extras);
        $this->assertTrue($extras->every(fn ($e) => $e->is_global && $e->menu_id === null));
        $this->assertSame('Extra Cheese', $extras[0]->getOriginal('title'));
        $this->assertSame('a.webp', $extras[0]->image);
        $this->assertNull($extras[2]->image);
    }

    public function test_bulk_store_strips_path_from_image_filename(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);

        $this->actingAs($owner)->postJson(route('modifiers.extras.bulkStore'), [
            'store_id' => $r->id,
            'items' => [['title' => 'X', 'price' => 1, 'image' => '../../etc/passwd']],
        ])->assertJson(['success' => true]);

        $this->assertSame('passwd', MenuExtra::where('store_id', $r->id)->first()->image);
    }

    public function test_bulk_store_rejects_foreign_restaurant(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeRestaurant($this->makeUser());

        $this->actingAs($owner)->postJson(route('modifiers.extras.bulkStore'), [
            'store_id' => $other->id,
            'items' => [['title' => 'Hack', 'price' => 1]],
        ])->assertForbidden();

        $this->assertSame(0, MenuExtra::where('store_id', $other->id)->count());
    }

    public function test_bulk_store_validates_items(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);

        $this->actingAs($owner)->postJson(route('modifiers.extras.bulkStore'), [
            'store_id' => $r->id, 'items' => [],
        ])->assertStatus(422);

        $this->actingAs($owner)->postJson(route('modifiers.extras.bulkStore'), [
            'store_id' => $r->id, 'items' => [['price' => 1]], // no title
        ])->assertStatus(422);
    }

    public function test_ai_title_returns_empty_when_image_unreadable(): void
    {
        // No AI is invoked when the image can't be read — endpoint stays graceful.
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);

        $this->actingAs($owner)->postJson(route('modifiers.extras.aiTitle'), [
            'store_id' => $r->id, 'image' => 'does-not-exist-' . Str::random(8) . '.webp',
        ])->assertJson(['success' => true, 'title' => '']);
    }

    public function test_ai_title_rejects_foreign_restaurant(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeRestaurant($this->makeUser());

        $this->actingAs($owner)->postJson(route('modifiers.extras.aiTitle'), [
            'store_id' => $other->id, 'image' => 'x.webp',
        ])->assertForbidden();
    }
}
