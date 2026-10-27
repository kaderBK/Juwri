<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use App\Services\ThemeCustomizerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Models\ThemeSetting;
use Juwri\Modules\Storefront\Services\Theme\KitThemes;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Customizer v2 — WI-S1: the preset engine.
 *
 * A preset is a curated starting point, not a storage concept: applying one
 * stages its values (over the theme defaults) as a draft the owner keeps
 * editing. What is pinned here: apply = preset ∪ defaults, it validates against
 * the schema (a bad preset can't reach a storefront), it stages a draft without
 * publishing, and it is ownership-scoped.
 */
class ThemePresetTest extends TestCase
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

    public function test_apply_is_the_preset_values_over_the_theme_defaults(): void
    {
        $schema = ThemeCustomizerService::schemaFor('crave');
        $values = ThemeCustomizerService::presetValues($schema, 'midnight');

        $this->assertNotNull($values, 'crave ships a "midnight" preset');
        // The preset's own values win…
        $this->assertSame('#7c5cff', $values['colors.primary']);
        $this->assertSame('#0a0a12', $values['colors.background']);
        $this->assertSame('pill', $values['layout.radius']);
        // …and everything it does not name falls back to the theme default (the
        // preset restyles colours, so the texts stay whatever the theme ships).
        $defaults = ThemeCustomizerService::defaults($schema);
        $this->assertSame($defaults['texts.menu_title'], $values['texts.menu_title']);
        $this->assertSame($defaults['texts.hero_title'], $values['texts.hero_title']);
    }

    public function test_every_shipped_preset_validates_and_has_a_thumbnail(): void
    {
        $seen = 0;
        $kitSeen = 0;
        foreach (glob(base_path('../assets/store_templates/*/theme.json')) as $path) {
            $theme = basename(dirname($path));
            $schema = ThemeCustomizerService::schemaFor($theme);
            foreach ($schema['presets'] ?? [] as $preset) {
                KitThemes::isKit($theme) ? $kitSeen++ : $seen++;
                // Every value a preset names must survive the schema — a bad
                // preset is exactly what would push an invalid colour to a storefront.
                $values = ThemeCustomizerService::presetValues($schema, $preset['id']);
                foreach ($preset['values'] ?? [] as $key => $val) {
                    $this->assertSame(
                        $val,
                        $values[$key] ?? null,
                        "Theme [{$theme}] preset [{$preset['id']}] value [{$key}] was dropped by validation"
                    );
                }
                // The thumbnail the card points at must exist (rendered by
                // `php artisan theme:preset-thumbs`, committed to the repo).
                $thumb = (string) ($preset['thumb'] ?? '');
                $this->assertNotSame('', $thumb, "Theme [{$theme}] preset [{$preset['id']}] has no thumb");
                $this->assertFileExists(
                    base_path('../'.$thumb),
                    "Theme [{$theme}] preset [{$preset['id']}] thumbnail is missing — run `php artisan theme:preset-thumbs`"
                );
            }
        }
        // K2: 5 per flagship (×3) + 3 per other (×14) = 57.
        $this->assertSame(57, $seen, 'expected 57 presets across all themes (K2)');
        // P3-00: the five imported kit themes (table 5, brew 4, aisle 4, basket 3, book 3).
        $this->assertSame(19, $kitSeen, 'expected 19 presets across the kit themes');
    }

    public function test_an_unknown_preset_is_null(): void
    {
        $schema = ThemeCustomizerService::schemaFor('crave');
        $this->assertNull(ThemeCustomizerService::presetValues($schema, 'no-such-preset'));
    }

    public function test_a_preset_value_that_would_fail_the_schema_is_dropped(): void
    {
        // A preset is authored data, held to the same contract as owner input —
        // an out-of-range value in one is never emitted, it is filtered out.
        $schema = [
            'version' => 2,
            'settings' => [['group' => 'C', 'fields' => [
                ['id' => 'colors.primary', 'type' => 'color', 'default' => '#000000'],
            ]]],
            'presets' => [['id' => 'bad', 'label' => 'Bad', 'values' => ['colors.primary' => 'not-a-hex']]],
            'sections' => [],
        ];

        $values = ThemeCustomizerService::presetValues($schema, 'bad');
        $this->assertArrayNotHasKey('colors.primary', $values);
    }

    public function test_applying_a_preset_stages_a_draft_without_publishing(): void
    {
        [$owner, $store] = $this->storeFixture();

        $response = $this->actingAs($owner)->postJson(route('theme.customizer.preset', $store->id), [
            'preset' => 'midnight',
        ])->assertOk()->assertJsonPath('ok', true);

        // The key itself contains a dot, so read the array rather than a json path.
        $this->assertSame('#7c5cff', $response->json('values')['colors.primary']);

        $ts = ThemeSetting::where('store_id', $store->id)->firstOrFail();
        $this->assertSame('#7c5cff', $ts->draft['colors.primary'], 'the preset is staged as a draft');
        // Nothing is published — the storefront still shows the theme default.
        $this->assertNotSame('#7c5cff', theme_setting($store->id, 'colors.primary', '#3fd463'));
    }

    public function test_an_unknown_preset_over_the_endpoint_is_a_clean_422(): void
    {
        [$owner, $store] = $this->storeFixture();

        $this->actingAs($owner)->postJson(route('theme.customizer.preset', $store->id), [
            'preset' => 'nope',
        ])->assertStatus(422)->assertJsonPath('ok', false);

        $this->assertDatabaseCount('theme_settings', 0);
    }

    public function test_apply_preset_is_scoped_to_the_owner(): void
    {
        [, $store] = $this->storeFixture();
        $stranger = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        $this->actingAs($stranger)
            ->postJson(route('theme.customizer.preset', $store->id), ['preset' => 'midnight'])
            ->assertNotFound();
    }

    /** @return array{0:User,1:Store} */
    private function storeFixture(string $template = 'crave'): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999], 'status' => 1,
        ]);
        $owner = User::factory()->create([
            'group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(),
        ]);
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
}
