<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use App\Services\ThemeCustomizerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Models\ThemeSetting;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Theme Studio B0.5: the trusted marketplace shell writes a Liquid theme's
 * styling — design tokens from the store's customizer values, self-hosted font
 * faces, the Riyal glyph and the section library stylesheet — with the CSP
 * nonce. Library-pinned themes get the shared Design controls.
 */
class StudioShellStylesTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'app/marketplace-themes/shelltest';

    private string $libBase;

    private string $libCss;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);

        $this->libBase = storage_path('framework/testing/liquid-library-shell');
        config(['platform.liquid_library.path' => $this->libBase]);
        File::ensureDirectoryExists($this->libBase.'/77/sections');
        File::put($this->libBase.'/77/sections/probe.liquid', '<p data-probe>probe</p>');
        $this->libCss = public_path('assets/liquid-library/77/library.css');
        File::ensureDirectoryExists(dirname($this->libCss));
        File::put($this->libCss, '.mbs-probe{color:var(--tc-primary)}');

        foreach (['pinned' => ['library' => '77'], 'plain' => []] as $v => $extra) {
            $dir = storage_path(self::BASE.'/'.$v);
            File::ensureDirectoryExists($dir.'/templates');
            File::put($dir.'/theme.json', json_encode(['name' => 'Shell', 'slug' => 'shelltest', 'version' => $v] + $extra));
            File::put($dir.'/templates/index.json', json_encode(['sections' => ['a' => ['type' => 'probe']], 'order' => ['a']]));
        }
        File::ensureDirectoryExists(storage_path(self::BASE.'/plain/sections'));
        File::put(storage_path(self::BASE.'/plain/sections/probe.liquid'), '<p data-probe>plain</p>');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path(self::BASE));
        File::deleteDirectory($this->libBase);
        File::deleteDirectory(dirname($this->libCss));
        parent::tearDown();
    }

    private function store(string $version, array $settings = [], array $draft = []): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => [
                'restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999,
                'category_limit' => 999, 'allow_ordering' => 1, 'table_qr' => 0,
                'allow_pro' => '0', 'allow_advanced' => '0', 'allow_on_free' => '0',
                'allow_on_try' => '0', 'allow_on_main' => '0', 'allow_viewonly' => '0',
                'allow_custom' => '0', 'allow_onlybio' => '0',
            ],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'S', 'description' => 'D',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', 'mp:shelltest@'.$version);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Item', 'price' => 5, 'active' => '1']);
        ThemeSetting::create(['store_id' => $store->id, 'template' => 'mp:shelltest', 'settings' => $settings, 'draft' => $draft ?: null]);

        return $store;
    }

    public function test_library_themes_get_the_shared_design_controls(): void
    {
        $pinned = ThemeCustomizerService::fields(ThemeCustomizerService::schemaFor('mp:shelltest@pinned'));
        $this->assertArrayHasKey('colors.primary', $pinned);
        $this->assertArrayHasKey('fonts.pair', $pinned);

        $plain = ThemeCustomizerService::fields(ThemeCustomizerService::schemaFor('mp:shelltest@plain'));
        $this->assertArrayNotHasKey('colors.primary', $plain, 'themes outside the library keep their own schema');
    }

    public function test_shell_writes_tokens_fonts_and_library_css_with_the_nonce(): void
    {
        $store = $this->store('pinned', ['colors.primary' => '#ff0000', 'fonts.pair' => 'jakarta', 'layout.radius' => 'pill']);

        $html = $this->get(route('publicView', $store->slug))->assertOk()->getContent();

        $this->assertStringContainsString('--tc-primary:#ff0000', str_replace(' ', '', $html));
        $this->assertStringContainsString('@font-face', $html);
        $this->assertStringContainsString('assets/liquid-library/77/library.css?v=', $html);
        $this->assertSame(
            0,
            preg_match('/<style(?![^>]*\snonce=)[^>]*>/i', $html),
            'every style tag carries the CSP nonce'
        );
        $this->assertStringContainsString('data-probe', $html);
    }

    public function test_untouched_store_emits_no_token_overrides_and_draft_tokens_stay_private(): void
    {
        $store = $this->store('pinned', [], ['colors.primary' => '#00ff00']);

        $public = $this->get(route('publicView', $store->slug).'?preview_theme=1')->assertOk()->getContent();
        $this->assertStringNotContainsString('--tc-primary', $public, 'overrides only — nothing set, nothing emitted');
        $this->assertStringContainsString('unicode-range:U+20C1', $public, 'Riyal glyph face always present');

        $this->actingAs(User::find($store->user_id));
        $preview = $this->get(route('publicView', $store->slug).'?preview_theme=1')->assertOk()->getContent();
        $this->assertStringContainsString('--tc-primary:#00ff00', str_replace(' ', '', $preview), 'owner sees draft tokens');
    }

    public function test_themes_outside_the_library_are_unchanged(): void
    {
        $store = $this->store('plain', ['colors.primary' => '#ff0000']);

        $html = $this->get(route('publicView', $store->slug))->assertOk()->getContent();

        $this->assertStringNotContainsString('--tc-primary', $html, 'no token schema → no token output');
        $this->assertStringNotContainsString('liquid-library', $html);
        $this->assertStringContainsString('<p data-probe>plain</p>', $html);
    }
}
