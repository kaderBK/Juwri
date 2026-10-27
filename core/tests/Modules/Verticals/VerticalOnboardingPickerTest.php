<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Verticals;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Services\FeatureAccess;
use App\Services\StorefrontThemeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Juwri\Core\Models\EntitlementOverride;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * rev/multi-vertical F0-5 — the onboarding wizard store-type picker.
 *
 * The create wizard offers a vertical picker; creating a store persists the chosen
 * store_type; the legacy path still defaults to 'restaurant'.
 */
class VerticalOnboardingPickerTest extends TestCase
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

    private function makeUser(): User
    {
        $plan = TestPlans::create(['name' => 'P', 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 200, 'settings' => (object) ['restaurant_limit' => 5], 'status' => 1]);

        return User::factory()->create(['status' => 1, 'email_verified_at' => now(), 'group_id' => (string) $plan->id]);
    }

    public function test_create_wizard_shows_the_type_picker(): void
    {
        $res = $this->actingAs($this->makeUser())->get(route('stores.create'));

        $res->assertOk();
        $res->assertSeeHtml('name="store_type"');
        $res->assertSee('Cosmetics');
        $res->assertSee('Car parts');
    }

    /**
     * The picker iterates config('verticals.types'), so EVERY configured
     * vertical must render as a radio option — a new vertical added to config
     * without a picker entry would otherwise ship a card with a blank hint and
     * the fallback 'store' icon (the bug hotel hit once it became the 8th
     * vertical). This asserts the whole set + hotel's now-present hint.
     */
    public function test_picker_renders_every_configured_vertical_including_hotel(): void
    {
        $html = $this->actingAs($this->makeUser())->get(route('stores.create'))
            ->assertOk()->getContent();

        foreach (array_keys(config('verticals.types')) as $type) {
            $this->assertStringContainsString('value="'.$type.'"', $html, "picker is missing a radio for the '{$type}' vertical");
        }
        // Hotel used to render a blank hint; it now carries its own copy.
        $this->assertStringContainsString('Rooms &amp; bookings with charge-to-room.', $html);
    }

    public function test_creating_a_store_persists_the_chosen_vertical(): void
    {
        $user = $this->makeUser();
        // F0-6 gate: non-restaurant verticals require the multi_vertical entitlement.
        EntitlementOverride::create(['user_id' => $user->id, 'feature_key' => 'multi_vertical', 'value' => '1', 'source_type' => 'admin']);
        FeatureAccess::flush();
        // rev/multi-vertical Lane C (C4): store_template is now scoped to
        // the chosen vertical's theme set — 'cosmetic' is 'retail', whose only
        // template today is `shelf` (C1); the food default slug used here
        // before C4 existed would now (correctly) fail validation.
        $template = 'shelf';

        $res = $this->actingAs($user)->from(route('stores.create'))->post(route('stores.store'), [
            'color' => '#111111',
            'title' => 'Glow Beauty',
            'description' => 'Cosmetics store',
            'address' => '1 St',
            'store_template' => $template,
            'store_type' => 'cosmetic',
            'cover_image' => UploadedFile::fake()->image('cover.jpg', 800, 400),
            'main_image' => UploadedFile::fake()->image('logo.jpg', 500, 500),
        ]);

        $store = Store::where('user_id', $user->id)->where('title', 'Glow Beauty')->first();
        $this->assertNotNull($store, 'store was not created');
        $this->assertSame('cosmetic', $store->store_type);
    }

    public function test_creating_without_a_type_defaults_to_restaurant(): void
    {
        $user = $this->makeUser();
        $template = app(StorefrontThemeService::class)->defaultSlug();

        $this->actingAs($user)->from(route('stores.create'))->post(route('stores.store'), [
            'color' => '#111111',
            'title' => 'Olive Table',
            'description' => 'A restaurant',
            'address' => '1 St',
            'store_template' => $template,
            'cover_image' => UploadedFile::fake()->image('cover.jpg', 800, 400),
            'main_image' => UploadedFile::fake()->image('logo.jpg', 500, 500),
        ]);

        $store = Store::where('user_id', $user->id)->where('title', 'Olive Table')->first();
        $this->assertNotNull($store, 'store was not created');
        $this->assertSame('restaurant', $store->store_type);
    }
}
