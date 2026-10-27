<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Models\StoreOption;
use App\Services\FeatureRegistry;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;

/**
 * Theme Studio C1 test fixture: a sectioned Studio theme `c1studio@1` (built on
 * section library 1, so library types are available too) with two own sections:
 *  - `probe`: text + richtext (printed via studio_text), image/category pickers,
 *    `cta` blocks (marked with studio_block);
 *  - `solo`: max_per_page 1.
 */
trait ThemeStudioFixture
{
    protected string $fixtureSlug = 'c1studio';

    protected function makeStudioTheme(): void
    {
        $dir = storage_path('app/marketplace-themes/'.$this->fixtureSlug.'/1');
        foreach (['templates', 'sections', 'locales'] as $d) {
            File::ensureDirectoryExists($dir.'/'.$d);
        }
        File::put($dir.'/theme.json', json_encode(['name' => 'C1 Studio', 'slug' => $this->fixtureSlug, 'version' => '1', 'library' => '1', 'vertical_groups' => ['food']]));
        File::put($dir.'/templates/index.json', json_encode([
            'sections' => [
                'hero' => [
                    'type' => 'probe',
                    'settings' => ['heading' => 'Welcome'],
                    'blocks' => ['b1' => ['type' => 'cta', 'settings' => ['label' => 'Order']]],
                    'block_order' => ['b1'],
                ],
                'solo' => ['type' => 'solo'],
            ],
            'order' => ['hero', 'solo'],
        ]));
        File::put($dir.'/sections/probe.liquid',
            '<h2 class="probe">{{ section.settings.heading | studio_text: \'heading\' }}</h2>'
            .'<div class="body">{{ section.settings.body | studio_text: \'body\' }}</div>'
            .'{% for block in section.blocks %}<i class="cta"{{ block | studio_block }}>{{ block.settings.label | escape }}</i>{% endfor %}'
            .'{% schema %}'.json_encode([
                'name' => 'Probe', 'group' => 'content', 'verticals' => ['all'],
                'settings' => [
                    ['id' => 'heading', 'type' => 'text', 'label' => 'Heading', 'default' => 'Hi'],
                    ['id' => 'body', 'type' => 'richtext', 'label' => 'Body', 'default' => ''],
                    ['id' => 'image', 'type' => 'image_picker', 'label' => 'Image', 'default' => ''],
                    ['id' => 'category', 'type' => 'category', 'label' => 'Category', 'default' => ''],
                ],
                'blocks' => [['type' => 'cta', 'name' => 'CTA', 'settings' => [['id' => 'label', 'type' => 'text', 'label' => 'Label', 'default' => 'Go']]]],
                'presets' => [['name' => 'Probe']],
            ]).'{% endschema %}');
        File::put($dir.'/sections/solo.liquid',
            '<p class="solo">solo</p>{% schema %}'.json_encode(['name' => 'Solo', 'group' => 'info', 'max_per_page' => 1, 'settings' => []]).'{% endschema %}');
    }

    protected function removeStudioTheme(): void
    {
        File::deleteDirectory(storage_path('app/marketplace-themes/'.$this->fixtureSlug));
    }

    /** Switch the kill switch; the plan grant decides the rest. */
    protected function studioFeature(bool $on): void
    {
        FeatureRegistry::clearCache();
        config(['settings.admin_allow_theme_studio' => $on ? 1 : 0]);
    }

    protected function languages(): void
    {
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'العربية', 'direction' => 'rtl', 'active' => 1]);
    }

    /** @param  array<string,mixed>  $planSettings  extra plan grants */
    protected function studioStore(bool $entitled = true, array $planSettings = [], ?string $template = null): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => array_merge([
                'restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999,
                'category_limit' => 999, 'allow_ordering' => 1, 'table_qr' => 0,
                'allow_pro' => '0', 'allow_advanced' => '0', 'allow_on_free' => '0',
                'allow_on_try' => '0', 'allow_on_main' => '0', 'allow_viewonly' => '0',
                'allow_custom' => '0', 'allow_onlybio' => '0',
                'theme_studio' => $entitled ? 1 : 0,
            ], $planSettings),
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return $this->studioStoreFor($owner, $template);
    }

    protected function studioStoreFor(User $owner, ?string $template = null): Store
    {
        $title = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'S', 'description' => 'D',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', $template ?? 'mp:'.$this->fixtureSlug.'@1');

        return $store;
    }
}
