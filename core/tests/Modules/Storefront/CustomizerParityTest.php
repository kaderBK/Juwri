<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Models\StoreOption;
use App\Services\ThemeCustomizerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Models\ThemeSetting;
use Juwri\Modules\Storefront\Services\Theme\KeptTemplates;
use Tests\TestCase;

/**
 * WI-G3: every modern template exposes a real, non-trivial customization schema,
 * and the shared-wired Splash group reaches parity across all 17. Each field the
 * schemas declare must be one the render side actually reads — no dead fields.
 */
class CustomizerParityTest extends TestCase
{
    use RefreshDatabase;

    private const TEMPLATES = KeptTemplates::MODERN;

    /**
     * splash.* ids the render side actually reads (render contract). `style` is
     * the Customizer v2 addition: `enabled` says whether there is a splash at
     * all and `style` picks whose design draws it — the shared partial or the
     * theme's own intro — so the two can never stack.
     */
    // Splash v2 added background / background_image / text_color / frequency /
    // cta_label — all read by store_templates/_partials/splash.blade.php.
    private const SPLASH_READS = [
        'enabled', 'style', 'image', 'title', 'subtitle', 'duration', 'show_language_picker',
        'background', 'background_image', 'text_color', 'frequency', 'cta_label',
        // Splash v2.1 — entrance animation + logo size/shape.
        'animation', 'logo_size', 'logo_shape',
    ];

    public function test_all_seventeen_schemas_load_and_validate(): void
    {
        foreach (self::TEMPLATES as $t) {
            $schema = ThemeCustomizerService::schemaFor($t);
            $this->assertNotEmpty($schema, "$t has a schema");
            $this->assertArrayHasKey('settings', $schema, "$t schema has settings");
            foreach ($schema['settings'] as $group) {
                $this->assertArrayHasKey('group', $group, "$t group has a name");
                foreach ($group['fields'] ?? [] as $f) {
                    $this->assertArrayHasKey('id', $f);
                    $this->assertArrayHasKey('type', $f);
                    $this->assertArrayHasKey('label', $f);
                }
            }
        }
    }

    public function test_splash_group_reaches_parity_across_all_templates(): void
    {
        foreach (self::TEMPLATES as $t) {
            $schema = ThemeCustomizerService::schemaFor($t);
            $groups = array_column($schema['settings'], 'group');
            $this->assertContains('Splash', $groups, "$t must expose the shared Splash group");
        }
    }

    public function test_no_dead_splash_fields(): void
    {
        // Every splash.* field any template declares must be one the shared splash
        // partial reads — otherwise it is a dead control.
        foreach (self::TEMPLATES as $t) {
            $schema = ThemeCustomizerService::schemaFor($t);
            foreach ($schema['settings'] as $group) {
                foreach ($group['fields'] ?? [] as $f) {
                    if (str_starts_with($f['id'], 'splash.')) {
                        $leaf = substr($f['id'], strlen('splash.'));
                        $this->assertContains($leaf, self::SPLASH_READS, "$t declares dead field {$f['id']}");
                    }
                }
            }
        }
    }

    public function test_splash_setting_round_trips_on_a_thin_template(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $store = Store::create([
            'user_id' => $owner->id, 'title' => 'Velora Demo', 'slug' => 'velora-parity',
            'color' => '#fff', 'sub_title' => 's', 'description' => 'd', 'main_image' => 'x.png',
            'cover_image' => 'x.png', 'address' => 'a', 'phone' => '1',
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', 'velora');
        ThemeSetting::create(['store_id' => $store->id, 'template' => 'velora', 'settings' => ['splash.title' => 'Welcome to Velora']]);

        $this->assertSame('Welcome to Velora', theme_setting($store->id, 'splash.title'));
    }
}
