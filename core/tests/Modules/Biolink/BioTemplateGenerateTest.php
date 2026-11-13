<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Ai\Services\AiService;
use Juwri\Modules\Biolink\Models\BioBlock;
use Juwri\Modules\Content\Services\Blog\Autopilot\FakeAutopilotAi;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * BIOLINK-TEMPLATES-RUN (Lane B · T1-4) — HTTP tests for the AI page generator.
 *
 * The AI provider is a bound FakeAutopilotAi (NEVER a live call): the REAL
 * BioGeneratorService + BioLinkService run against it, so the prompt/validator,
 * metering tag, gate, tenant scope, seeding limit and hidden-when-missing rule
 * are all proven end-to-end through the controller. Gate: two-layer —
 * `feature:ai_biolink` kill-switch (middleware) + `plan_feature('ai_biolink', owner)`
 * plan entitlement (controller).
 */
class BioTemplateGenerateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        // Kill-switches ON so the gates are reachable (ship dark by default).
        config(['settings.admin_allow_biolink' => true, 'settings.admin_allow_ai_biolink' => true]);
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    /** Bind a fresh fake AI so the real generator uses it (no network). */
    private function bindAi(): FakeAutopilotAi
    {
        $ai = new FakeAutopilotAi;
        $this->app->instance(AiService::class, $ai);

        return $ai;
    }

    /** A valid proposal the fake returns for the generate call. */
    private function proposal(): array
    {
        return [
            'archetype' => 'cafe',
            'preset_suggestion' => 'classic_flat',
            'blocks' => [
                ['type' => 'menu', 'label' => 'See the menu', 'priority' => 9],
                ['type' => 'announcement', 'label' => 'Open late', 'config' => ['text' => 'Open until 2am'], 'priority' => 5],
                ['type' => 'capture', 'label' => 'Join our list', 'priority' => 3],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $planSettings
     * @return array{0: User, 1: Store}
     */
    private function storeOnPlan(array $planSettings = ['ai_biolink' => '1', 'biolink' => '1']): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10,
            'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => $planSettings + ['restaurant_limit' => 5, 'menu_limit' => 999, 'bio_blocks_limit' => 999],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(), 'user_type' => 'user']);
        $title = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'Best coffee', 'description' => 'A neighbourhood cafe',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1',
        ]);

        return [$owner, $store];
    }

    private function staff(Store $store, array $permissions = []): StaffMember
    {
        return StaffMember::create([
            'owner_id' => $store->user_id, 'store_id' => $store->id,
            'name' => 'S '.Str::random(4), 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => 'manager', 'active' => 1,
        ] + ($permissions ? ['permissions' => $permissions] : []));
    }

    /* ─────────────────────────── generate ─────────────────────────────────── */

    public function test_owner_generates_a_proposal(): void
    {
        [$owner, $store] = $this->storeOnPlan();
        $this->bindAi()->pushJson($this->proposal());

        $res = $this->actingAs($owner)->postJson(route('biolink.template.generate'))
            ->assertOk()
            ->assertJson(['success' => true]);

        $proposal = $res->json('proposal');
        $this->assertSame('cafe', $proposal['archetype']);
        $this->assertSame('classic_flat', $proposal['preset_suggestion']);
        $this->assertCount(3, $proposal['blocks']);
        $this->assertSame('menu', $proposal['blocks'][0]['type']);
    }

    public function test_generate_meters_with_the_bio_generate_tag_and_user(): void
    {
        [$owner, $store] = $this->storeOnPlan();
        $ai = $this->bindAi();
        $ai->pushJson($this->proposal());

        $this->actingAs($owner)->postJson(route('biolink.template.generate'))->assertOk();

        $calls = $ai->callsFor('json');
        $this->assertCount(1, $calls);
        $this->assertSame('bio_generate', $calls[0]['feature']);           // metering tag (T1-4)
        $this->assertSame($owner->id, $calls[0]['userId']);                // spend metered to the owner
        // Untrusted store content is in a USER message, never system.
        $roles = array_column($calls[0]['messages'], 'role');
        $this->assertSame(['system', 'user'], $roles);
    }

    public function test_generate_writes_nothing(): void
    {
        [$owner, $store] = $this->storeOnPlan();
        $this->bindAi()->pushJson($this->proposal());

        $this->actingAs($owner)->postJson(route('biolink.template.generate'))->assertOk();

        // Proposal only — never auto-applies (SPEC T1-4).
        $this->assertSame(0, BioBlock::where('store_id', $store->id)->count());
    }

    public function test_generate_is_killed_by_the_switch(): void
    {
        [$owner, $store] = $this->storeOnPlan();
        $this->bindAi();
        config(['settings.admin_allow_ai_biolink' => false]);

        $this->actingAs($owner)->postJson(route('biolink.template.generate'))->assertStatus(403);
    }

    public function test_generate_requires_the_plan_entitlement(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        // Kill-switch ON but the plan does NOT sell ai_biolink → controller 403.
        [$owner, $store] = $this->storeOnPlan(['biolink' => '1']);   // no ai_biolink
        $this->bindAi()->pushJson($this->proposal());

        $this->actingAs($owner)->postJson(route('biolink.template.generate'))->assertStatus(403);
    }

    public function test_generate_is_tenant_scoped_idor(): void
    {
        [$ownerA] = $this->storeOnPlan();
        [, $postB] = $this->storeOnPlan();
        $this->bindAi()->pushJson($this->proposal());

        // Owner A aims the generate at owner B's store → 403.
        $this->actingAs($ownerA)->postJson(route('biolink.template.generate', ['store_id' => $postB->id]))
            ->assertStatus(403);
    }

    public function test_generate_returns_clean_error_on_provider_outage(): void
    {
        [$owner, $store] = $this->storeOnPlan();
        $this->bindAi()->failAll();

        $this->actingAs($owner)->postJson(route('biolink.template.generate'))
            ->assertStatus(503)
            ->assertJson(['success' => false]);
    }

    public function test_staff_without_biolink_permission_cannot_generate(): void
    {
        [$owner, $store] = $this->storeOnPlan();
        $staff = $this->staff($store, ['biolink' => false]);
        $this->bindAi()->pushJson($this->proposal());

        $this->actingAs($owner, 'web')->withSession(['staff_session_id' => $staff->id])
            ->postJson(route('biolink.template.generate'))->assertStatus(403);
    }

    /* ─────────────────────────── apply ────────────────────────────────────── */

    public function test_apply_seeds_reviewed_blocks(): void
    {
        [$owner, $store] = $this->storeOnPlan();
        // A real menu item so a menu_item block would resolve; the menu block gets the store URL.
        Menu::create(['store_id' => $store->id, 'name' => 'Latte', 'price' => 4, 'active' => '1']);

        $this->actingAs($owner)->postJson(route('biolink.template.generate.apply'), [
            'proposal' => $this->proposal(),
        ])->assertOk()->assertJson(['success' => true, 'created' => 3]);

        $blocks = BioBlock::where('store_id', $store->id)->whereNull('branch_id')->orderBy('position')->get();
        $this->assertCount(3, $blocks);
        // Highest priority (menu, 9) seeded first, with the store's real menu URL.
        $this->assertSame('menu', $blocks[0]->type);
        $this->assertStringContainsString($store->slug, (string) $blocks[0]->url);
        $this->assertTrue((bool) $blocks[0]->active);
    }

    public function test_apply_hides_a_block_whose_data_is_missing(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        [$owner, $store] = $this->storeOnPlan();   // reservations NOT sold on this plan

        $this->actingAs($owner)->postJson(route('biolink.template.generate.apply'), [
            'proposal' => [
                'archetype' => 'fine_dining',
                'blocks' => [
                    ['type' => 'reservation', 'label' => 'Book a table', 'priority' => 8],
                ],
            ],
        ])->assertOk();

        $block = BioBlock::where('store_id', $store->id)->where('type', 'reservation')->first();
        $this->assertNotNull($block);
        // Hidden-when-missing: reservations feature off → seeded inactive, not faked.
        $this->assertFalse((bool) $block->active);
    }

    public function test_apply_respects_the_plan_block_limit_by_priority(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        [$owner, $store] = $this->storeOnPlan(['ai_biolink' => '1', 'biolink' => '1', 'bio_blocks_limit' => 1]);

        $this->actingAs($owner)->postJson(route('biolink.template.generate.apply'), [
            'proposal' => $this->proposal(),   // 3 blocks, top priority = menu(9)
        ])->assertOk()->assertJson(['created' => 1]);

        $blocks = BioBlock::where('store_id', $store->id)->get();
        $this->assertCount(1, $blocks);
        $this->assertSame('menu', $blocks[0]->type);   // the highest-priority block survives
    }

    public function test_apply_replace_clears_the_main_page_first(): void
    {
        [$owner, $store] = $this->storeOnPlan();
        BioBlock::create(['store_id' => $store->id, 'branch_id' => null, 'type' => 'link', 'label' => 'Old', 'url' => 'https://old.test', 'config' => [], 'position' => 0, 'active' => true]);

        $this->actingAs($owner)->postJson(route('biolink.template.generate.apply'), [
            'proposal' => $this->proposal(),
            'replace' => '1',
        ])->assertOk()->assertJson(['created' => 3]);

        // Old block gone, only the 3 new ones remain.
        $this->assertSame(0, BioBlock::where('store_id', $store->id)->where('label', 'Old')->count());
        $this->assertSame(3, BioBlock::where('store_id', $store->id)->count());
    }

    public function test_apply_without_replace_appends(): void
    {
        [$owner, $store] = $this->storeOnPlan();
        BioBlock::create(['store_id' => $store->id, 'branch_id' => null, 'type' => 'link', 'label' => 'Keep', 'url' => 'https://keep.test', 'config' => [], 'position' => 0, 'active' => true]);

        $this->actingAs($owner)->postJson(route('biolink.template.generate.apply'), [
            'proposal' => $this->proposal(),
        ])->assertOk();

        $this->assertSame(1, BioBlock::where('store_id', $store->id)->where('label', 'Keep')->count());
        $this->assertSame(4, BioBlock::where('store_id', $store->id)->count());   // 1 kept + 3 new
    }

    public function test_apply_is_killed_and_tenant_scoped(): void
    {
        [$owner, $store] = $this->storeOnPlan();
        [, $postB] = $this->storeOnPlan();

        // IDOR: owner cannot apply to another store.
        $this->actingAs($owner)->postJson(route('biolink.template.generate.apply', ['store_id' => $postB->id]), [
            'proposal' => $this->proposal(),
        ])->assertStatus(403);

        // Kill-switch off → 403, nothing written.
        config(['settings.admin_allow_ai_biolink' => false]);
        $this->actingAs($owner)->postJson(route('biolink.template.generate.apply'), ['proposal' => $this->proposal()])
            ->assertStatus(403);
        $this->assertSame(0, BioBlock::where('store_id', $store->id)->count());
    }

    public function test_demo_mode_blocks_generate_and_apply(): void
    {
        User::factory()->create();   // ensure owner is not user id 1 (demo-exempt)
        [$owner, $store] = $this->storeOnPlan();
        $this->bindAi()->pushJson($this->proposal());
        config(['app.demo_mode' => true]);

        $this->actingAs($owner)->postJson(route('biolink.template.generate'));
        $this->actingAs($owner)->postJson(route('biolink.template.generate.apply'), ['proposal' => $this->proposal()]);

        // The `demo` middleware intercepts POST writes before the controller.
        $this->assertSame(0, BioBlock::where('store_id', $store->id)->count());
    }
}
