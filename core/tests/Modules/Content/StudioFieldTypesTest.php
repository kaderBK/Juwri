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
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Content\Services\LockedTemplates\RichText;
use Juwri\Modules\Media\Models\MediaAsset;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Services\Theme\FontPairs;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Theme Studio B0.8 — customizer field types for Liquid (Studio) themes:
 * range, number, textarea, image_picker, font_pair, color_token, richtext,
 * url and the store-owned pickers category / item_list / branch.
 *
 * Covers the three layers: the UI mapping (uiField), the value contract
 * (validateSectionSettings for layouts, validate for flat customizer values —
 * shape only without a store, ownership with one) and the editor page that
 * renders the controls.
 */
class StudioFieldTypesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        ThemeCustomizerService::flushSchemaMemo();

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function uiField(array $def): array
    {
        return new \ReflectionMethod(ThemeCustomizerService::class, 'uiField')->invoke(null, $def);
    }

    /** A section schema declaring one setting of every B0.8 type. */
    private function sectionSchema(): array
    {
        return ['settings' => [
            ['id' => 'cols', 'type' => 'range', 'min' => 1, 'max' => 4, 'step' => 1, 'default' => 2],
            ['id' => 'count', 'type' => 'number', 'min' => 0, 'max' => 50],
            ['id' => 'body', 'type' => 'textarea'],
            ['id' => 'photo', 'type' => 'image_picker'],
            ['id' => 'fonts', 'type' => 'font_pair'],
            ['id' => 'tone', 'type' => 'color_token'],
            ['id' => 'about', 'type' => 'richtext'],
            ['id' => 'link', 'type' => 'url'],
            ['id' => 'cat', 'type' => 'category'],
            ['id' => 'picks', 'type' => 'item_list'],
            ['id' => 'few', 'type' => 'item_list', 'max' => 3],
            ['id' => 'shop', 'type' => 'branch'],
        ]];
    }

    /** @return array{0: User, 1: Store} */
    private function store(): array
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

        return [$owner, $store];
    }

    /** @return array{category:int, items:list<int>, branch:int, media:string} */
    private function rowsFor(Store $store, string $tag): array
    {
        $category = MenuCategory::create(['store_id' => $store->id, 'name' => "Mains {$tag}", 'position' => 1]);
        $items = [];
        foreach ([1, 2] as $n) {
            $items[] = (int) Menu::create([
                'store_id' => $store->id, 'category_id' => $category->id,
                'name' => "Dish {$tag}{$n}", 'price' => 5, 'active' => '1', 'position' => $n,
            ])->id;
        }
        $branch = Branch::create(['store_id' => $store->id, 'name' => "Branch {$tag}", 'active' => 1, 'position' => 0]);
        $filename = "photo-{$tag}.webp";
        MediaAsset::create(['store_id' => $store->id, 'filename' => $filename, 'original_name' => 'p.webp', 'folder' => 'theme', 'size_bytes' => 10]);

        return ['category' => (int) $category->id, 'items' => $items, 'branch' => (int) $branch->id, 'media' => $filename];
    }

    // ── UI mapping ───────────────────────────────────────────────────────

    public function test_ui_field_keeps_each_studio_type_with_its_constraints(): void
    {
        $range = $this->uiField(['id' => 'r', 'type' => 'range', 'min' => 1, 'max' => '4', 'step' => 0.5, 'unit' => 'px']);
        $this->assertSame('range', $range['type']);
        $this->assertSame([1, 4, 0.5, 'px'], [$range['min'], $range['max'], $range['step'], $range['unit']]);

        $font = $this->uiField(['id' => 'f', 'type' => 'font_pair']);
        $this->assertSame('font_pair', $font['type']);
        $this->assertSame(FontPairs::optionValues(), $font['options']);
        $this->assertArrayHasKey(FontPairs::noneValue(), $font['option_labels']);

        $token = $this->uiField(['id' => 't', 'type' => 'color_token']);
        $this->assertSame(['primary', 'background', 'surface', 'text', 'accent'], $token['options']);

        $this->assertSame(24, $this->uiField(['id' => 'i', 'type' => 'item_list'])['max']);
        $this->assertSame(24, $this->uiField(['id' => 'i', 'type' => 'item_list', 'max' => 99])['max'], 'a def can never raise the cap');
        $this->assertSame(6, $this->uiField(['id' => 'i', 'type' => 'item_list', 'max' => 6])['max']);

        foreach (['number', 'textarea', 'image_picker', 'richtext', 'url', 'category', 'branch'] as $type) {
            $this->assertSame($type, $this->uiField(['id' => 'x', 'type' => $type])['type']);
        }
        $this->assertSame('help me', $this->uiField(['id' => 'x', 'type' => 'url', 'info' => 'help me'])['help']);
    }

    // ── value contract, shape only (no store) ────────────────────────────

    public function test_valid_values_of_every_type_pass(): void
    {
        $about = ThemeCustomizerService::sanitizeSectionSettings($this->sectionSchema(), [
            'about' => '<b>Fresh</b> <a href="https://example.com">daily</a>',
        ])['about'];

        $this->assertSame([], ThemeCustomizerService::validateSectionSettings($this->sectionSchema(), [
            'cols' => 3,
            'count' => '12',
            'body' => "Line one\nLine two",
            'photo' => 'a1b2c3.webp',
            'fonts' => FontPairs::ids()[0],
            'tone' => 'accent',
            'about' => $about,
            'link' => 'https://example.com/menu?x=1',
            'cat' => 7,
            'picks' => [1, '2', 3],
            'few' => [1, 2, 3],
            'shop' => '4',
        ]));
        // Empty values mean "nothing chosen" and are fine too.
        $this->assertSame([], ThemeCustomizerService::validateSectionSettings($this->sectionSchema(), [
            'photo' => '', 'link' => '', 'picks' => [], 'fonts' => FontPairs::noneValue(),
        ]));
        $this->assertSame([], ThemeCustomizerService::validateSectionSettings($this->sectionSchema(), [
            'photo' => '', 'link' => '/pages/about', 'picks' => [], 'fonts' => FontPairs::noneValue(),
        ]));
    }

    public function test_invalid_values_are_rejected_per_type(): void
    {
        $cases = [
            'cols' => [0, 5, 'two', true],
            'count' => [-1, 51, 'x'],
            'body' => [42, str_repeat('a', 5001)],
            'photo' => ['../etc/passwd', 'https://evil.test/x.png', 'dir/x.png', '.htaccess', 5],
            'fonts' => ['comic-sans', '', 'Theme default'],
            'tone' => ['#ff0000', 'primary-500', 'Primary', ''],
            'about' => ['<script>alert(1)</script>hi', '<img src=x onerror=alert(1)>', ['x']],
            'link' => ['javascript:alert(1)', 'data:text/html,hi', '//evil.test', 'http://a b', 'mailto:x@y.z', '"/><x', 'relative/path', 12],
            'cat' => [0, -3, 'abc', '1.5', [1], null],
            'picks' => ['1,2', [0], ['x'], [1, 1], range(1, 25), ['a' => 1]],
            'few' => [[1, 2, 3, 4]],
            'shop' => ['', 'main', 0],
        ];
        foreach ($cases as $key => $bad) {
            foreach ($bad as $value) {
                $errors = ThemeCustomizerService::validateSectionSettings($this->sectionSchema(), [$key => $value]);
                $this->assertArrayHasKey($key, $errors, "{$key} must reject ".json_encode($value));
            }
        }
    }

    public function test_richtext_is_sanitized_to_the_allow_list_then_validates(): void
    {
        $dirty = '<p onclick="x()">Hi <b>there</b><script>alert(1)</script> <a href="javascript:alert(1)">bad</a> <a href="/menu">menu</a></p>';

        $clean = ThemeCustomizerService::sanitizeSectionSettings($this->sectionSchema(), ['about' => $dirty, 'cols' => '2'])['about'];

        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringContainsString('<strong>there</strong>', $clean);
        $this->assertSame($clean, RichText::clean($clean, ThemeCustomizerService::RICHTEXT_MAX, true, true), 'canonical');
        $this->assertSame([], ThemeCustomizerService::validateSectionSettings($this->sectionSchema(), ['about' => $clean]));
        // A number typed into a number/range input arrives as a string and is normalized.
        $this->assertSame(2, ThemeCustomizerService::sanitizeSectionSettings($this->sectionSchema(), ['cols' => '2'])['cols']);
    }

    public function test_existing_types_keep_their_behaviour(): void
    {
        $schema = ['settings' => [
            ['id' => 'h', 'type' => 'text'],
            ['id' => 'c', 'type' => 'color'],
            ['id' => 'b', 'type' => 'checkbox'],
            ['id' => 's', 'type' => 'select', 'options' => ['a', ['value' => 'b']]],
            ['id' => 'x', 'type' => 'made_up'],
        ]];
        $this->assertSame([], ThemeCustomizerService::validateSectionSettings($schema, [
            'h' => 'Hi', 'c' => '#fff', 'b' => true, 's' => 'b', 'x' => 'anything',
        ]));
        $this->assertSame(['h', 'c', 'b', 's', 'x', 'nope'], array_keys(ThemeCustomizerService::validateSectionSettings($schema, [
            'h' => 1, 'c' => 'red', 'b' => 'yes', 's' => 'z', 'x' => [], 'nope' => 'x',
        ])));
    }

    // ── value contract with a store: ownership ───────────────────────────

    public function test_with_a_store_pickers_and_images_must_be_the_stores_own(): void
    {
        [, $mine] = $this->store();
        [, $theirs] = $this->store();
        $own = $this->rowsFor($mine, 'a');
        $foreign = $this->rowsFor($theirs, 'b');
        $schema = $this->sectionSchema();

        $this->assertSame([], ThemeCustomizerService::validateSectionSettings($schema, [
            'cat' => $own['category'], 'picks' => $own['items'], 'shop' => $own['branch'], 'photo' => $own['media'],
        ], $mine));

        $errors = ThemeCustomizerService::validateSectionSettings($schema, [
            'cat' => $foreign['category'],
            'picks' => [$own['items'][0], $foreign['items'][0]], // one foreign id sinks the list
            'shop' => $foreign['branch'],
            'photo' => $foreign['media'],
        ], $mine);
        $this->assertSame(['cat', 'picks', 'shop', 'photo'], array_keys($errors));

        // Without a store the same foreign ids pass the SHAPE check (layout
        // callers that have no store context keep working).
        $this->assertSame([], ThemeCustomizerService::validateSectionSettings($schema, [
            'cat' => $foreign['category'], 'shop' => $foreign['branch'], 'photo' => $foreign['media'],
        ]));
    }

    public function test_flat_customizer_values_use_the_same_contract_and_null_clears(): void
    {
        [, $mine] = $this->store();
        [, $theirs] = $this->store();
        $own = $this->rowsFor($mine, 'c');
        $foreign = $this->rowsFor($theirs, 'd');

        $fields = [];
        foreach ($this->sectionSchema()['settings'] as $def) {
            $field = $this->uiField($def);
            $field['id'] = 'sections.s1.'.$def['id'];
            $fields[] = $field;
        }
        $schema = ['settings' => [['group' => 'S1', 'fields' => $fields]], 'sections' => [['id' => 's1']]];

        $values = ThemeCustomizerService::sanitizeValues($schema, [
            'sections.s1.cols' => '4',
            'sections.s1.about' => '<i>hi</i><script>x</script>',
            'sections.s1.link' => ' /menu ',
            'sections.s1.cat' => $own['category'],
            'sections.s1.picks' => $own['items'],
            'sections.s1.photo' => $own['media'],
            'sections.s1.tone' => null, // clear back to the default
        ]);
        $this->assertSame(4, $values['sections.s1.cols']);
        $this->assertSame('/menu', $values['sections.s1.link']);
        $this->assertStringNotContainsString('script', $values['sections.s1.about']);
        $this->assertSame([], ThemeCustomizerService::validate($schema, $values, $mine));

        $errors = ThemeCustomizerService::validate($schema, [
            'sections.s1.cat' => $foreign['category'],
            'sections.s1.link' => 'javascript:alert(1)',
            'sections.s1.tone' => '#123456',
            'sections.s1.cols' => 9,
        ], $mine);
        $this->assertSame(['sections.s1.cat', 'sections.s1.link', 'sections.s1.tone', 'sections.s1.cols'], array_keys($errors));
    }

    public function test_picker_options_list_only_the_stores_rows(): void
    {
        [, $mine] = $this->store();
        [, $theirs] = $this->store();
        $own = $this->rowsFor($mine, 'e');
        $this->rowsFor($theirs, 'f');

        $options = ThemeCustomizerService::pickerOptions($mine);

        $this->assertSame([$own['category']], array_column($options['category'], 'id'));
        $this->assertSame($own['items'], array_column($options['item_list'], 'id'));
        // A new store may already carry its auto-created default branch.
        $branchIds = array_column($options['branch'], 'id');
        $this->assertContains($own['branch'], $branchIds);
        $this->assertSame([], array_intersect($branchIds, Branch::where('store_id', $theirs->id)->pluck('id')->map(fn ($id) => (int) $id)->all()));
        $this->assertSame(Branch::where('store_id', $mine->id)->count(), count($branchIds));
        $this->assertSame('Dish e1', $options['item_list'][0]['name']);
    }

    // ── the editor page ──────────────────────────────────────────────────

    public function test_customizer_page_renders_the_studio_controls_with_the_stores_pickers(): void
    {
        [$owner, $store] = $this->store();
        StoreOption::updateStoreOption($store->id, 'store_template', 'crave');
        $own = $this->rowsFor($store, 'g');
        [, $other] = $this->store();
        $this->rowsFor($other, 'h');

        $html = $this->actingAs($owner)
            ->get(route('theme.customizer.page', ['store' => $store->id]))
            ->assertOk()
            ->getContent();

        foreach (["f.type==='range'", "f.type==='textarea'", "f.type==='richtext'", "f.type==='url'",
            "f.type==='font_pair' || f.type==='color_token'", "f.type==='category' || f.type==='branch'",
            "f.type==='item_list'", "f.type==='image_picker'", 'uploadMediaFor(f.id', route('media.store')] as $needle) {
            $this->assertStringContainsString($needle, $html, "missing: {$needle}");
        }
        // The pickers carry this store's rows — and never another store's.
        $this->assertStringContainsString('Dish g1', $html);
        $this->assertStringContainsString('Branch g', $html);
        $this->assertStringNotContainsString('Dish h1', $html);
        $this->assertStringNotContainsString('Branch h', $html);
        $this->assertSame(1, substr_count($html, '"id":'.$own['category'].',"name":"Mains g"'));
    }
}
