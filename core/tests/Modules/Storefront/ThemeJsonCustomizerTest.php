<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use App\Services\ThemeCustomizerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Models\ThemeSetting;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Platform P3: theme.json-driven customizer — schema validation (DATA only, no code),
 * draft → publish, and theme_setting() read-back.
 */
class ThemeJsonCustomizerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function storeFixture(string $template = 'aurora'): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999], 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', $template);

        return [$owner, $store];
    }

    public function test_validate_rejects_unknown_and_bad_types(): void
    {
        $schema = ThemeCustomizerService::schemaFor('aurora');
        $this->assertNotEmpty($schema, 'aurora theme.json should load');

        $errors = ThemeCustomizerService::validate($schema, [
            'colors.primary' => 'not-a-color',   // bad color
            'search.enabled' => 'yes',            // must be bool
            'fonts.body' => 'ComicSans',      // not an option
            'bogus.key' => 'x',              // unknown
            'sections.hero' => true,             // valid section toggle
        ]);

        $this->assertArrayHasKey('colors.primary', $errors);
        $this->assertArrayHasKey('search.enabled', $errors);
        $this->assertArrayHasKey('fonts.body', $errors);
        $this->assertArrayHasKey('bogus.key', $errors);
        $this->assertArrayNotHasKey('sections.hero', $errors);
    }

    public function test_validate_accepts_valid_values(): void
    {
        $schema = ThemeCustomizerService::schemaFor('aurora');
        $errors = ThemeCustomizerService::validate($schema, [
            'colors.primary' => '#ff8800',
            'search.enabled' => false,
            'fonts.body' => 'Poppins',
            'texts.hero_title' => 'Hello',
            'sections.specials' => false,
        ]);
        $this->assertSame([], $errors);
    }

    public function test_draft_save_publish_and_theme_setting(): void
    {
        [$owner, $store] = $this->storeFixture('aurora');

        // Save a draft.
        $this->actingAs($owner)->postJson(route('theme.customizer.draft', $store->id), [
            'values' => ['colors.primary' => '#123456'],
        ])->assertOk();

        // Draft is stored, but the DRAFT value is NOT yet the published value
        // (published still shows the schema default until publish).
        $this->assertNotSame('#123456', theme_setting($store->id, 'colors.primary'));

        // Publish → becomes the live value; draft cleared.
        $this->actingAs($owner)->postJson(route('theme.customizer.publish', $store->id))->assertOk();

        $this->assertSame('#123456', theme_setting($store->id, 'colors.primary'));
        $this->assertNull(ThemeSetting::where('store_id', $store->id)->first()->draft);
    }

    public function test_draft_rejects_invalid_via_endpoint(): void
    {
        [$owner, $store] = $this->storeFixture('aurora');
        $this->actingAs($owner)->postJson(route('theme.customizer.draft', $store->id), [
            'values' => ['colors.primary' => 'nope'],
        ])->assertStatus(422);
    }

    public function test_cannot_customize_another_owners_restaurant(): void
    {
        [, $store] = $this->storeFixture('aurora');
        $intruder = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $this->actingAs($intruder)->getJson(route('theme.customizer.schema', $store->id))->assertStatus(404);
    }

    /**
     * T6 X2: theme_setting() merges the DRAFT over published values ONLY for the
     * authenticated owner previewing with ?preview_theme=1. Everyone else — public
     * visitors and non-owners — always sees the published value.
     */
    public function test_theme_setting_draft_merge_for_owner_preview_only(): void
    {
        [$owner, $store] = $this->storeFixture('aurora');
        ThemeSetting::create([
            'store_id' => $store->id,
            'template' => 'aurora',
            'settings' => ['texts.hero_title' => 'Published Title'],
            'draft' => ['texts.hero_title' => 'Draft Preview Title'],
        ]);

        $bindRequest = function (string $uri, ?User $user) {
            $request = Request::create($uri, 'GET');
            $this->app->instance('request', $request);
            if ($user) {
                $this->be($user);
            } else {
                $this->app['auth']->forgetGuards();
            }
        };

        // Public visitor, even with the flag: published only.
        $bindRequest('/?preview_theme=1', null);
        $this->assertSame('Published Title', theme_setting($store->id, 'texts.hero_title'));

        // Owner without the flag: published only.
        $bindRequest('/', $owner);
        $this->assertSame('Published Title', theme_setting($store->id, 'texts.hero_title'));

        // Non-owner with the flag: published only.
        $intruder = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $bindRequest('/?preview_theme=1', $intruder);
        $this->assertSame('Published Title', theme_setting($store->id, 'texts.hero_title'));

        // Owner WITH the flag: draft merged over published.
        $bindRequest('/?preview_theme=1', $owner);
        $this->assertSame('Draft Preview Title', theme_setting($store->id, 'texts.hero_title'));
    }

    /**
     * Clearing a text override (empty input → null via ConvertEmptyStringsToNull)
     * must be accepted, and publishing the null tombstone removes the key so
     * theme_setting() falls back to the theme default again.
     */
    public function test_empty_text_override_clears_back_to_default(): void
    {
        [$owner, $store] = $this->storeFixture('aurora');

        // Set + publish an override.
        $this->actingAs($owner)->postJson(route('theme.customizer.draft', $store->id), [
            'values' => ['texts.hero_title' => 'Custom welcome'],
        ])->assertOk();
        $this->actingAs($owner)->postJson(route('theme.customizer.publish', $store->id))->assertOk();
        $this->assertSame('Custom welcome', theme_setting($store->id, 'texts.hero_title', 'FALLBACK'));

        // Clearing the field posts null (ConvertEmptyStringsToNull) — must NOT 422.
        $this->actingAs($owner)->postJson(route('theme.customizer.draft', $store->id), [
            'values' => ['texts.hero_title' => null],
        ])->assertOk();

        // Publish: tombstone removes the key → helper falls back.
        $this->actingAs($owner)->postJson(route('theme.customizer.publish', $store->id))->assertOk();

        $settings = ThemeSetting::where('store_id', $store->id)->first()->settings;
        $this->assertArrayNotHasKey('texts.hero_title', $settings);
        $this->assertSame('FALLBACK', theme_setting($store->id, 'texts.hero_title', 'FALLBACK'));
    }
}
