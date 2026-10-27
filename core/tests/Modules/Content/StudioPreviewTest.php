<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use App\Models\Theme;
use App\Models\ThemeVersion;
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
 * Theme Studio B0.4: the owner can preview their unpublished draft (settings +
 * layout) on a Liquid theme with the same ?preview_theme=1 gate the Blade
 * themes use — nobody else ever sees it — and the marketplace preview renders
 * in any demo language, including Arabic right-to-left.
 */
class StudioPreviewTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'app/marketplace-themes/previewtest';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);

        $dir = storage_path(self::BASE.'/1');
        foreach (['templates', 'sections', 'config', 'locales'] as $d) {
            File::ensureDirectoryExists($dir.'/'.$d);
        }
        File::put($dir.'/config/settings_schema.json', json_encode([
            ['name' => 'Brand', 'settings' => [['id' => 'tagline', 'type' => 'text', 'label' => 'Tagline', 'default' => 'Default']]],
        ]));
        File::put($dir.'/templates/index.json', json_encode(['sections' => ['a' => ['type' => 'probe']], 'order' => ['a']]));
        File::put($dir.'/sections/probe.liquid',
            '<p data-probe="{{ section.id }}">{{ settings.tagline | escape }}|{{ \'Studio preview greeting\' | t }}</p>');
        File::put($dir.'/sections/other.liquid', '<p data-other>other</p>');
        File::put($dir.'/locales/ar.json', json_encode(['Studio preview greeting' => 'أهلاً بالمعاينة']));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path(self::BASE));
        parent::tearDown();
    }

    private function store(): Store
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
        StoreOption::updateStoreOption($store->id, 'store_template', 'mp:previewtest@1');
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Item', 'price' => 5, 'active' => '1']);

        ThemeSetting::create([
            'store_id' => $store->id,
            'template' => 'mp:previewtest',
            'settings' => ['tagline' => 'Published'],
            'draft' => ['tagline' => 'Drafted'],
            'layout' => ['sections' => ['live' => ['type' => 'probe']], 'order' => ['live']],
            'layout_draft' => ['sections' => ['new' => ['type' => 'probe'], 'o' => ['type' => 'other']], 'order' => ['o', 'new']],
        ]);

        return $store;
    }

    public function test_owner_sees_the_draft_and_nobody_else_does(): void
    {
        $store = $this->store();
        $url = route('publicView', $store->slug).'?preview_theme=1';

        // Guest with the preview flag → published.
        $guest = $this->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('data-probe="live">Published|', $guest);
        $this->assertStringNotContainsString('Drafted', $guest);

        // Another signed-in user → published.
        $this->actingAs(User::factory()->create());
        $stranger = $this->get($url)->assertOk()->getContent();
        $this->assertStringNotContainsString('Drafted', $stranger);
        $this->assertStringNotContainsString('data-other', $stranger);

        // The owner without the flag → published; with it → draft settings + draft layout.
        $this->actingAs(User::find($store->user_id));
        $this->assertStringNotContainsString('Drafted', $this->get(route('publicView', $store->slug))->getContent());

        $res = $this->get($url)->assertOk();
        $html = $res->getContent();
        $this->assertStringContainsString('data-probe="new">Drafted|', $html);
        $this->assertTrue(strpos($html, 'data-other') < strpos($html, 'data-probe="new"'), 'draft order');
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
    }

    public function test_marketplace_preview_renders_arabic_right_to_left(): void
    {
        $theme = Theme::create(['name' => 'Preview Test', 'slug' => 'previewtest', 'status' => 'published', 'engine' => 'liquid']);
        ThemeVersion::create(['theme_id' => $theme->id, 'semver' => '1', 'manifest' => [], 'checksum' => str_repeat('0', 64), 'status' => 'published']);

        $en = $this->get(route('marketplace.preview', 'previewtest'))->assertOk()->getContent();
        $this->assertStringContainsString('dir="ltr"', $en);
        $this->assertStringContainsString('Default|Studio preview greeting', $en);

        $ar = $this->get(route('marketplace.preview', 'previewtest').'?lang=ar')->assertOk()->getContent();
        $this->assertStringContainsString('<html lang="ar" dir="rtl">', $ar);
        $this->assertStringContainsString('Default|أهلاً بالمعاينة', $ar, 'theme Arabic strings');

        $unknown = $this->get(route('marketplace.preview', 'previewtest').'?lang=xx')->assertOk()->getContent();
        $this->assertStringContainsString('dir="ltr"', $unknown, 'unknown language falls back to the default');
    }
}
