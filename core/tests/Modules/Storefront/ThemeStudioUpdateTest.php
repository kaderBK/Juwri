<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Theme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Models\ThemeSetting;
use Juwri\Modules\Storefront\Services\ThemeStudio\LayoutDocument;
use Juwri\Modules\Storefront\Services\ThemeStudio\StoreLayouts;
use Juwri\Modules\Storefront\Services\ThemeUpdateService;
use Juwri\Modules\Storefront\Storefront\MarketplaceThemeResolver;
use Tests\TestCase;

/**
 * Theme Studio C5: a theme update keeps store layouts working. Section schemas
 * may rename settings (`"migrations": {"old": "new"}`, section or block level)
 * — stored values and their translations follow; section / block types the new
 * version no longer ships are dropped from the layout with an owner notice the
 * editor shows (state `notices[]`) until dismissed.
 */
class ThemeStudioUpdateTest extends TestCase
{
    use RefreshDatabase;
    use ThemeStudioFixture;

    private const SLUG = 'c5upd';

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureSlug = self::SLUG;
        app()->setLocale('en');
        $this->languages();
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->studioFeature(true);
        $this->makeVersions();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/marketplace-themes/'.self::SLUG));
        parent::tearDown();
    }

    private function section(string $dir, string $type, array $schema, string $body = '<p>x</p>'): void
    {
        File::put($dir.'/sections/'.$type.'.liquid', $body.'{% schema %}'.json_encode($schema).'{% endschema %}');
    }

    /**
     * 1.0.0: probe (heading · blocks cta{label} + promo) and `gone`.
     * 2.0.0: probe renames heading → title and cta.label → text, drops the promo
     *        block type; `gone` no longer exists.
     */
    private function makeVersions(): void
    {
        foreach (['1.0.0', '2.0.0'] as $v) {
            $dir = storage_path('app/marketplace-themes/'.self::SLUG.'/'.$v);
            foreach (['templates', 'sections'] as $d) {
                File::ensureDirectoryExists($dir.'/'.$d);
            }
            File::put($dir.'/theme.json', json_encode(['name' => 'C5 Update', 'slug' => self::SLUG, 'version' => $v, 'library' => '1']));
            File::put($dir.'/templates/index.json', json_encode(['sections' => ['hero' => ['type' => 'probe']], 'order' => ['hero']]));
        }

        $v1 = storage_path('app/marketplace-themes/'.self::SLUG.'/1.0.0');
        $this->section($v1, 'probe', [
            'name' => 'Probe',
            'settings' => [['id' => 'heading', 'type' => 'text', 'label' => 'Heading', 'default' => 'Hi']],
            'blocks' => [
                ['type' => 'cta', 'name' => 'CTA', 'settings' => [['id' => 'label', 'type' => 'text', 'label' => 'Label']]],
                ['type' => 'promo', 'name' => 'Promo', 'settings' => [['id' => 'note', 'type' => 'text', 'label' => 'Note']]],
            ],
        ], '<h2 class="probe">{{ section.settings.heading | escape }}</h2>');
        $this->section($v1, 'gone', ['name' => 'Gone', 'settings' => [['id' => 't', 'type' => 'text', 'label' => 'T']]]);

        $v2 = storage_path('app/marketplace-themes/'.self::SLUG.'/2.0.0');
        $this->section($v2, 'probe', [
            'name' => 'Probe',
            'settings' => [['id' => 'title', 'type' => 'text', 'label' => 'Title', 'default' => 'Hi']],
            'migrations' => ['heading' => 'title'],
            'blocks' => [
                ['type' => 'cta', 'name' => 'CTA', 'settings' => [['id' => 'text', 'type' => 'text', 'label' => 'Text']], 'migrations' => ['label' => 'text']],
            ],
        ], '<h2 class="probe">{{ section.settings.title | escape }}</h2>{% for block in section.blocks %}<i>{{ block.settings.text | escape }}</i>{% endfor %}');

        $theme = Theme::create(['name' => 'C5 Update', 'slug' => self::SLUG, 'status' => 'published', 'price' => 0]);
        $theme->versions()->create(['semver' => '1.0.0', 'manifest' => [], 'checksum' => str_repeat('a', 64), 'status' => 'published']);
        $theme->versions()->create(['semver' => '2.0.0', 'manifest' => [], 'checksum' => str_repeat('b', 64), 'status' => 'published']);
    }

    /** @return array<string,mixed> a 1.0.0 layout using every piece 2.0.0 changes */
    private function oldLayout(): array
    {
        return [
            'schema' => 1,
            'sections' => [
                'hero' => [
                    'type' => 'probe',
                    'settings' => ['heading' => 'Mine'],
                    'blocks' => ['b1' => ['type' => 'cta', 'settings' => ['label' => 'Go now']], 'b2' => ['type' => 'promo', 'settings' => ['note' => 'Sale']]],
                    'block_order' => ['b2', 'b1'],
                ],
                'g' => ['type' => 'gone', 'settings' => ['t' => 'bye']],
            ],
            'order' => ['g', 'hero'],
            'theme' => [],
            'i18n' => ['ar' => ['hero.heading' => 'عنواني', 'hero.b1.label' => 'اذهب', 'hero.b2.note' => 'تخفيض', 'g.t' => 'وداعاً']],
        ];
    }

    private function store(): Store
    {
        $store = $this->studioStore(true, [], 'mp:'.self::SLUG.'@1.0.0');
        ThemeSetting::create([
            'store_id' => $store->id, 'template' => 'mp:'.self::SLUG,
            'settings' => [], 'layout' => $this->oldLayout(), 'layout_draft' => $this->oldLayout(),
        ]);

        return $store;
    }

    private function assertMigrated(array $layout): void
    {
        $hero = $layout['sections']['hero'];
        $this->assertSame(['title' => 'Mine'], $hero['settings'], 'renamed section setting');
        $this->assertSame(['b1'], array_keys($hero['blocks']), 'removed block type dropped');
        $this->assertSame(['b1'], $hero['block_order']);
        $this->assertSame(['text' => 'Go now'], $hero['blocks']['b1']['settings'], 'renamed block setting');
        $this->assertArrayNotHasKey('g', $layout['sections'], 'removed section type dropped');
        $this->assertSame(['hero'], $layout['order']);
        $this->assertSame(['ar' => ['hero.title' => 'عنواني', 'hero.b1.text' => 'اذهب']], $layout['i18n'], 'translations follow renames / removals');
    }

    public function test_theme_update_migrates_published_and_draft_layouts_with_notices(): void
    {
        $store = $this->store();

        $template = app(ThemeUpdateService::class)->updateRestaurant($store);
        $this->assertSame('mp:'.self::SLUG.'@2.0.0', $template);

        $row = ThemeSetting::where('store_id', $store->id)->where('template', 'mp:'.self::SLUG)->first();
        $this->assertMigrated($row->layout);
        $this->assertMigrated($row->layout_draft);

        $notices = StoreLayouts::notices($store);
        $this->assertCount(2, $notices);
        $this->assertEqualsCanonicalizing(['section_removed', 'block_removed'], array_column($notices, 'kind'));
        $removed = collect($notices)->firstWhere('kind', 'section_removed');
        $this->assertSame('g', $removed['sid']);
        $this->assertSame('gone', $removed['type']);
        $this->assertSame('2.0.0', $removed['version']);

        // The migrated layout validates against 2.0.0 and renders.
        $theme = MarketplaceThemeResolver::resolve($template);
        $this->assertSame([], LayoutDocument::validate($theme, LayoutDocument::normalize($row->layout), $store));

        // Idempotent: running the hook again changes nothing and adds no notice.
        $this->assertSame([], app(ThemeUpdateService::class)->migrateLayouts($store, $template));
        $this->assertCount(2, StoreLayouts::notices($store));
    }

    public function test_editor_state_lists_notices_until_dismissed(): void
    {
        $store = $this->store();
        app(ThemeUpdateService::class)->updateRestaurant($store);
        $owner = User::findOrFail($store->user_id);

        $state = $this->actingAs($owner)->getJson(route('theme.studio.state', ['store' => $store->id]))->assertOk()->json();
        $this->assertCount(2, $state['notices']);
        $messages = implode(' ', array_column($state['notices'], 'message'));
        $this->assertStringContainsString('Gone', $messages);
        $this->assertStringContainsString('Promo', $messages);
        $this->assertArrayNotHasKey('g', $state['layout']['sections']);

        $this->actingAs($owner)->putJson(route('theme.studio.draft', ['store' => $store->id]), [
            'layout' => $state['layout'], 'dismiss_notices' => true,
        ])->assertOk();
        $this->assertSame([], StoreLayouts::notices($store));
        $this->assertSame([], $this->actingAs($owner)->getJson(route('theme.studio.state', ['store' => $store->id]))->json('notices'));
    }

    public function test_state_migrates_a_layout_left_behind_by_an_out_of_band_update(): void
    {
        // The pin moved without ThemeUpdateService (e.g. a reference theme bump).
        $store = $this->studioStore(true, [], 'mp:'.self::SLUG.'@2.0.0');
        ThemeSetting::create(['store_id' => $store->id, 'template' => 'mp:'.self::SLUG, 'settings' => [], 'layout_draft' => $this->oldLayout()]);

        $state = $this->actingAs(User::findOrFail($store->user_id))
            ->getJson(route('theme.studio.state', ['store' => $store->id]))->assertOk()->json();

        $this->assertMigrated($state['layout']);
        $this->assertCount(2, $state['notices']);
    }

    public function test_a_rename_is_skipped_while_the_old_setting_is_still_declared(): void
    {
        $v2 = storage_path('app/marketplace-themes/'.self::SLUG.'/2.0.0');
        $this->section($v2, 'probe', [
            'name' => 'Probe',
            'settings' => [['id' => 'heading', 'type' => 'text', 'label' => 'H'], ['id' => 'title', 'type' => 'text', 'label' => 'T']],
            'migrations' => ['heading' => 'title', 'x' => 'missing'],
            'blocks' => [['type' => 'cta', 'name' => 'CTA', 'settings' => [['id' => 'label', 'type' => 'text', 'label' => 'L']]], ['type' => 'promo', 'name' => 'P', 'settings' => [['id' => 'note', 'type' => 'text', 'label' => 'N']]]],
        ]);
        $theme = MarketplaceThemeResolver::resolve('mp:'.self::SLUG.'@2.0.0');

        $layout = $this->oldLayout();
        unset($layout['sections']['g']);
        $layout['order'] = ['hero'];
        $layout['i18n'] = ['ar' => ['hero.heading' => 'عنواني']];
        $result = LayoutDocument::migrate($theme, LayoutDocument::normalize($layout));

        $this->assertFalse($result['changed']);
        $this->assertSame([], $result['notices']);
        $this->assertSame(['heading' => 'Mine'], $result['layout']['sections']['hero']['settings']);
    }
}
