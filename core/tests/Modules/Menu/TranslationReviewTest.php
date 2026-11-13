<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Ai\Models\TranslationMemory;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

class TranslationReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            EnsureStaffDashboardPermission::class,
            Installed::class,
        ]);
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);
        app()->setLocale('en');

        $settings = new class(['theme_color' => '#111', 'site_title' => 'T', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'f.png'])
        {
            public function __construct(private array $d) {}

            public function __get($k): mixed
            {
                return $this->d[$k] ?? null;
            }

            public function __isset($k): bool
            {
                return array_key_exists($k, $this->d);
            }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect()]);
    }

    private function owner(): array
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 'x', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'category_limit' => 999], 'status' => 1]);
        $user = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create(['user_id' => $user->id, 'slug' => Str::slug('r'.Str::random(6)), 'color' => '#111', 'title' => 'R', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now()]);
        StoreSettings::set($store->id, 'menu_languages', json_encode(['en', 'ar']));
        StoreSettings::set($store->id, 'default_language', 'en');

        return [$user, $store];
    }

    public function test_review_page_lists_items_needing_attention(): void
    {
        [$user, $store] = $this->owner();
        Menu::create(['store_id' => $store->id, 'name' => 'Water', 'description' => '', 'price' => 2, 'active' => '1',
            'translations' => (object) ['ar' => (object) ['title' => 'AR-Water', '_machine' => true, '_src_hash' => 'h']]]);

        $res = $this->actingAs($user)->get(route('stores.translations.review', $store->id));
        $res->assertOk();
        $res->assertSeeHtml('Water');      // source
        $res->assertSeeHtml('AR-Water');   // machine translation shown for review
    }

    public function test_save_writes_owner_translation_and_memory(): void
    {
        [$user, $store] = $this->owner();
        $item = Menu::create(['store_id' => $store->id, 'name' => 'Water', 'description' => '', 'price' => 2, 'active' => '1']);

        $this->actingAs($user)->postJson(route('stores.translations.save', $store->id), [
            'type' => 'item', 'id' => $item->id, 'lang' => 'ar', 'title' => 'ماء',
        ])->assertOk()->assertJsonPath('success', true);

        $item->refresh();
        $this->assertSame('ماء', $item->translations->ar->title);
        $this->assertFalse(isset($item->translations->ar->_machine)); // approved
        $this->assertSame('ماء', TranslationMemory::lookup($store->id, 'ar', 'Water'));
    }

    public function test_approve_all_clears_machine_flags_and_learns(): void
    {
        [$user, $store] = $this->owner();
        $item = Menu::create(['store_id' => $store->id, 'name' => 'Water', 'description' => '', 'price' => 2, 'active' => '1',
            'translations' => (object) ['ar' => (object) ['title' => 'AR-Water', '_machine' => true, '_src_hash' => 'h', '_lowconf' => true]]]);

        $res = $this->actingAs($user)->postJson(route('stores.translations.approve', $store->id));
        $res->assertOk()->assertJsonPath('approved', 1);

        $item->refresh();
        $this->assertSame('AR-Water', $item->translations->ar->title);
        $this->assertFalse(isset($item->translations->ar->_machine));
        $this->assertSame('AR-Water', TranslationMemory::lookup($store->id, 'ar', 'Water'));
    }

    public function test_cross_tenant_blocked(): void
    {
        [$intruder] = $this->owner();
        [, $victim] = $this->owner();
        $item = Menu::create(['store_id' => $victim->id, 'name' => 'Water', 'description' => '', 'price' => 2, 'active' => '1',
            'translations' => (object) ['ar' => (object) ['title' => 'AR-Water', '_machine' => true, '_src_hash' => 'h']]]);
        $this->actingAs($intruder)->get(route('stores.translations.review', $victim->id))->assertNotFound(); // BL-043: the owner tenant scope hides the other account's row (404)
        $this->actingAs($intruder)->postJson(route('stores.translations.approve', $victim->id))->assertNotFound();
        $this->assertTrue(isset($item->fresh()->translations->ar->_machine), 'the victim\'s machine translation is not approved');
        $this->assertNull(TranslationMemory::lookup($victim->id, 'ar', 'Water'));
    }
}
