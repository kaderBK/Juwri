<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Biolink\Services\BioLinkService;
use Juwri\Modules\Menu\Models\Menu;
use Tests\TestCase;

/**
 * Biolink Templates run — Lane C (dashboard UI) HTTP tests.
 *
 * Like {@see BioLinkDashboardTest}, the engine is bound as a fixture STUB so the
 * controller/route/view wiring is proven against the locked service contract
 * independent of Lane A/B. Covers: C1 empty-state chooser + apply-template
 * endpoint, C2 AI card gating, C3 menu-item picker, C4 translate button, and C5
 * copy-to-store (ownership both directions).
 */
class BioLinkTemplatesTest extends TestCase
{
    use RefreshDatabase;

    /** @var object bound service stub */
    private $svc;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        // Kill-switch ON so the `feature:biolink` dashboard gate is reachable.
        config(['settings.admin_allow_biolink' => true]);
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $this->shareLayoutGlobals(['currency_sign' => '$', 'currency_pos' => 1]);

        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);

        $this->svc = $this->makeStub();
        app()->instance(BioLinkService::class, $this->svc);
    }

    private function makeStub(): object
    {
        return new class
        {
            public array $blocks = [];

            public array $settings = ['enabled' => true, 'preset' => 'classic_flat', 'mode' => 'menu_first', 'sections' => ['hours' => true], 'branch_pages' => false];

            public array $presetList = [
                ['slug' => 'classic_flat', 'name' => 'Classic Flat', 'family' => 'list', 'tier' => 'basic'],
                ['slug' => 'classic_dark', 'name' => 'Classic Dark', 'family' => 'list', 'tier' => 'basic'],
                ['slug' => 'midnight_glass', 'name' => 'Midnight Glass', 'family' => 'list', 'tier' => 'basic'],
                ['slug' => 'menu_card', 'name' => 'Menu Card', 'family' => 'menu_card', 'tier' => 'basic'],
                ['slug' => 'majlis_gold', 'name' => 'Majlis Gold', 'family' => 'list', 'tier' => 'basic'],
                ['slug' => 'social_grid', 'name' => 'Social Grid', 'family' => 'social_grid', 'tier' => 'pro'],
                ['slug' => 'neon_arcade', 'name' => 'Neon Arcade', 'family' => 'list', 'tier' => 'pro'],
            ];

            public array $locales = [];

            // recorders
            public array $applied = [];

            public array $created = [];

            public array $deleted = [];

            public array $saved = [];

            public array $markedMigrated = [];

            public function resolveSettings(int $storeId): array
            {
                return $this->settings;
            }

            public function blocksFor(int $storeId, ?int $branchId = null, bool $includeHidden = false): array
            {
                return $this->blocks;
            }

            public function editorBlocks(int $storeId, ?int $branchId = null): array
            {
                return $this->blocks;
            }

            public function presetAllowed(int $storeId, string $preset): bool
            {
                return in_array($preset, ['classic_flat', 'classic_dark', 'glass_gradient', 'midnight_glass', 'menu_card', 'majlis_gold'], true);
            }

            public function presets(): array
            {
                return $this->presetList;
            }

            public function enabledLocales(int $storeId): array
            {
                return $this->locales;
            }

            public function statsFor(int $storeId, string $range): array
            {
                return ['views' => 0, 'clicks' => 0, 'ctr' => 0, 'qr_scans' => 0, 'per_block' => [], 'sources' => [], 'devices' => []];
            }

            public function saveSettings(int $storeId, array $settings): void
            {
                $this->saved[] = compact('storeId', 'settings');
            }

            public function markMigrated(int $storeId): void
            {
                $this->markedMigrated[] = $storeId;
            }

            public function createBlock(int $storeId, ?int $branchId, array $data): array
            {
                $this->created[] = compact('storeId', 'branchId', 'data');

                return ['id' => count($this->created)] + $data;
            }

            public function deleteBlock(int $storeId, int $id): void
            {
                $this->deleted[] = compact('storeId', 'id');
            }

            public function applyStarter(int $storeId, string $preset, bool $replace = false): int
            {
                $this->applied[] = compact('storeId', 'preset', 'replace');

                return 5;
            }
        };
    }

    /** @return array{0: User, 1: Store} */
    private function store(): array
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now(), 'user_type' => 'user']);

        return [$owner, $this->postFor($owner)];
    }

    private function postFor(User $owner): Store
    {
        $title = 'R'.Str::random(6);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1',
        ]);
    }

    /* ─────────────────────────── C1 · chooser ─────────────────────────────── */

    public function test_chooser_renders_on_empty_page(): void
    {
        [$owner] = $this->store();
        $this->svc->blocks = []; // zero blocks → chooser

        $res = $this->actingAs($owner)->get(route('biolink.builder'))->assertStatus(200);

        $res->assertSeeHtml('data-biolink-chooser');
        $res->assertSee(___('Start blank'));
        $res->assertSee(___('Restaurant essentials'));       // a curated archetype card
        $res->assertSee(___('Browse all 38 designs'));
        // Apply form posts to the template.apply route.
        $res->assertSeeHtml('action="'.route('biolink.template.apply').'"');
    }

    public function test_chooser_shows_pro_lock_on_locked_curated_card(): void
    {
        [$owner] = $this->store();
        $this->svc->blocks = [];

        // social_grid is pro (locked by the stub) → its curated card is an upsell.
        $this->actingAs($owner)->get(route('biolink.builder'))->assertStatus(200)->assertSeeHtml('bio-starter-locked')
            ->assertSee(___('Pro'));
    }

    public function test_chooser_absent_when_blocks_exist(): void
    {
        [$owner] = $this->store();
        $this->svc->blocks = [['id' => 1, 'type' => 'link', 'label' => 'One', 'config' => [], 'active' => true]];

        // The chooser CARD (its header + Start-blank CTA) must be gone; the JS
        // selector string in the builder script is not the chooser itself.
        $this->actingAs($owner)->get(route('biolink.builder'))
            ->assertStatus(200)
            ->assertDontSee(___('Start your bio page'))
            ->assertDontSee(___('Browse all 38 designs'));
    }

    /* ─────────────────────── C1 · apply-template endpoint ──────────────────── */

    public function test_apply_template_seeds_via_service(): void
    {
        [$owner] = $this->store();
        $this->svc->blocks = [];

        // F0-merge: the canonical endpoint is Lane A's applyTemplate — it returns the
        // seeded count as `created` (C's isolated duplicate used `blocks`; dropped).
        $this->actingAs($owner)->postJson(route('biolink.template.apply'), ['preset' => 'classic_flat'])
            ->assertOk()->assertJson(['success' => true, 'created' => 5]);

        $this->assertCount(1, $this->svc->applied);
        $this->assertSame('classic_flat', $this->svc->applied[0]['preset']);
        $this->assertFalse($this->svc->applied[0]['replace']);
    }

    public function test_apply_locked_preset_is_forbidden(): void
    {
        [$owner] = $this->store();
        $this->svc->blocks = [];

        $this->actingAs($owner)->postJson(route('biolink.template.apply'), ['preset' => 'neon_arcade'])
            ->assertStatus(403);

        $this->assertCount(0, $this->svc->applied);
    }

    public function test_apply_without_replace_on_nonempty_page_is_422(): void
    {
        [$owner] = $this->store();
        $this->svc->blocks = [['id' => 1, 'type' => 'link', 'config' => [], 'active' => true]];

        $this->actingAs($owner)->postJson(route('biolink.template.apply'), ['preset' => 'classic_flat'])
            ->assertStatus(422);

        $this->assertCount(0, $this->svc->applied);
    }

    public function test_apply_with_replace_on_nonempty_page_succeeds(): void
    {
        [$owner] = $this->store();
        $this->svc->blocks = [['id' => 1, 'type' => 'link', 'config' => [], 'active' => true]];

        $this->actingAs($owner)->postJson(route('biolink.template.apply'), ['preset' => 'classic_flat', 'replace' => '1'])
            ->assertOk();

        $this->assertCount(1, $this->svc->applied);
        $this->assertTrue($this->svc->applied[0]['replace']);
    }

    public function test_apply_template_rejects_foreign_store(): void
    {
        [$owner] = $this->store();
        [$otherOwner, $foreign] = $this->store();

        // Owner acts, but names a store they do not control → 403 (IDOR guard).
        $this->actingAs($owner)->postJson(route('biolink.template.apply', ['store_id' => $foreign->id]), ['preset' => 'classic_flat'])
            ->assertStatus(403);

        $this->assertCount(0, $this->svc->applied);
    }

    /* ─────────────────────── C2 · AI card + review UI ──────────────────────── */

    public function test_ai_card_and_review_absent_when_ai_biolink_off(): void
    {
        [$owner] = $this->store();
        $this->svc->blocks = []; // chooser is shown, but the AI card must NOT be

        // ai_biolink is not a shipped registry key in this tree → the whole AI
        // surface renders NOTHING (absent, not a disabled button).
        // Assert on markup-only strings — the always-present builder script
        // contains the data-biolink-ai-review SELECTOR, so panel-only user text is
        // the honest signal that the surface itself did not render.
        $this->actingAs($owner)->get(route('biolink.builder'))->assertStatus(200)->assertSeeHtml('data-biolink-chooser')     // chooser present
            ->assertDontSee(___('Generate with AI'))->assertDontSee(___('Apply this draft'))->assertDontSeeHtml('data-biolink-ai-card');
    }

    public function test_ai_card_present_when_enabled(): void
    {
        // F0-merge: Lane B registered `ai_biolink`; the skip guard is removed so
        // this is now an unconditional assertion.
        [$owner] = $this->store();
        $this->svc->blocks = [];
        config(['settings.admin_allow_ai_biolink' => true]);

        $this->actingAs($owner)->get(route('biolink.builder'))
            ->assertStatus(200)
            ->assertSee(___('Generate with AI'))
            ->assertSee(___('Apply this draft'));
    }

    /* ─────────────────────── C3 · menu-item picker ─────────────────────────── */

    public function test_block_editor_menu_item_picker_is_grouped_by_category(): void
    {
        [$owner, $store] = $this->store();
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'position' => 1]);
        $item = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Falafel Wrap', 'price' => 12, 'active' => '1', 'position' => 1]);

        $res = $this->actingAs($owner)->get(route('biolink.builder'))->assertStatus(200);

        // The editor's item field is now a <select> grouped by category, not a raw id input.
        $res->assertSeeHtml('name="config[item_id]"');
        $res->assertSeeHtml('<optgroup label="Mains"');
        $res->assertSeeHtml('<option value="'.$item->id.'">Falafel Wrap</option>');
        $res->assertDontSee(___('Menu item ID'));
    }

    public function test_menu_item_picker_hides_inactive_and_foreign_items(): void
    {
        [$owner, $store] = $this->store();
        [$other, $foreign] = $this->store();
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'position' => 1]);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Hidden Dish', 'price' => 9, 'active' => '0', 'position' => 2]);
        $fcat = MenuCategory::create(['store_id' => $foreign->id, 'name' => 'Theirs', 'position' => 1]);
        Menu::create(['store_id' => $foreign->id, 'category_id' => $fcat->id, 'name' => 'Foreign Dish', 'price' => 9, 'active' => '1', 'position' => 1]);

        $this->actingAs($owner)->get(route('biolink.builder'))
            ->assertStatus(200)
            ->assertDontSee('Hidden Dish')     // inactive item excluded
            ->assertDontSee('Foreign Dish');   // another store's item never listed
    }

    /* ─────────────────────── C4 · translate button ────────────────────────── */

    public function test_translate_button_absent_when_ai_off(): void
    {
        [$owner] = $this->store();
        $this->svc->locales = ['en', 'ar'];   // multi-locale
        $this->svc->blocks = [['id' => 1, 'type' => 'link', 'label' => 'X', 'config' => [], 'active' => true]];

        // ai_biolink off (unregistered) → the translate control renders nothing.
        $this->actingAs($owner)->get(route('biolink.builder'))
            ->assertStatus(200)
            ->assertDontSee(___('Translate page'));
    }

    public function test_translate_button_present_when_enabled_and_multilocale(): void
    {
        // F0-merge: Lane B registered `ai_biolink`; skip guard removed.
        [$owner] = $this->store();
        config(['settings.admin_allow_ai_biolink' => true]);
        $this->svc->locales = ['en', 'ar'];
        $this->svc->blocks = [['id' => 1, 'type' => 'link', 'label' => 'X', 'config' => [], 'active' => true]];

        $this->actingAs($owner)->get(route('biolink.builder'))
            ->assertStatus(200)
            ->assertSee(___('Translate page'));
    }

    /* ─────────────────────────── C5 · copy-to-store ────────────────────────── */

    public function test_copy_card_lists_other_stores(): void
    {
        [$owner, $store] = $this->store();
        $other = $this->postFor($owner);

        $this->actingAs($owner)->get(route('biolink.index', ['store_id' => $store->id]))
            ->assertStatus(200)
            ->assertSee(___('Copy to another store'))->assertSee($other->title)->assertSeeHtml('action="'.route('biolink.copy-to').'"');
    }

    public function test_copy_card_hidden_for_single_store_owner(): void
    {
        [$owner, $store] = $this->store();

        $this->actingAs($owner)->get(route('biolink.index'))
            ->assertStatus(200)
            ->assertDontSee(___('Copy to another store'));
    }

    public function test_copy_to_replaces_target_and_keeps_it_unpublished(): void
    {
        [$owner, $source] = $this->store();
        $target = $this->postFor($owner);
        $this->svc->blocks = [
            ['id' => 1, 'type' => 'link', 'label' => 'A', 'url' => 'https://a.test', 'config' => ['_i18n' => ['ar' => ['label' => 'أ']]], 'active' => true, 'visible_from' => null, 'visible_until' => null],
            ['id' => 2, 'type' => 'whatsapp', 'label' => 'Chat', 'url' => null, 'config' => [], 'active' => false, 'visible_from' => null, 'visible_until' => null],
        ];

        $this->actingAs($owner)->postJson(route('biolink.copy-to', ['store_id' => $source->id]), ['target' => $target->id])
            ->assertOk()->assertJson(['success' => true]);

        // Settings copied to the TARGET with enabled forced OFF.
        $savedTarget = collect($this->svc->saved)->firstWhere('storeId', $target->id);
        $this->assertNotNull($savedTarget);
        $this->assertFalse($savedTarget['settings']['enabled']);

        // Target marked migrated (so createBlock won't synthesize legacy links).
        $this->assertContains($target->id, $this->svc->markedMigrated);

        // Target's existing blocks were deleted, then the source's re-created — all
        // scoped to the TARGET store, never the source.
        $this->assertNotEmpty($this->svc->deleted);
        foreach ($this->svc->deleted as $d) {
            $this->assertSame($target->id, $d['storeId']);
        }
        $this->assertCount(2, $this->svc->created);
        foreach ($this->svc->created as $c) {
            $this->assertSame($target->id, $c['storeId']);
            $this->assertNull($c['branchId']);   // main-page only
        }
        // The _i18n override rides along in the copied config.
        $this->assertSame('أ', $this->svc->created[0]['data']['config']['_i18n']['ar']['label']);
    }

    public function test_copy_to_forbids_foreign_target(): void
    {
        [$owner, $source] = $this->store();
        [$other, $foreign] = $this->store();

        // Target is another owner's store → 403 (dest-direction IDOR).
        $this->actingAs($owner)->postJson(route('biolink.copy-to', ['store_id' => $source->id]), ['target' => $foreign->id])
            ->assertStatus(403);

        $this->assertCount(0, $this->svc->created);
    }

    public function test_copy_to_forbids_foreign_source(): void
    {
        [$owner, $mine] = $this->store();
        [$other, $foreign] = $this->store();

        // Source is another owner's store → 403 (source-direction IDOR).
        $this->actingAs($owner)->postJson(route('biolink.copy-to', ['store_id' => $foreign->id]), ['target' => $mine->id])
            ->assertStatus(403);

        $this->assertCount(0, $this->svc->created);
    }

    public function test_copy_to_rejects_copying_onto_itself(): void
    {
        [$owner, $store] = $this->store();

        $this->actingAs($owner)->postJson(route('biolink.copy-to', ['store_id' => $store->id]), ['target' => $store->id])
            ->assertStatus(403);

        $this->assertCount(0, $this->svc->created);
    }
}
