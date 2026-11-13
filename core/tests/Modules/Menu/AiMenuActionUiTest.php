<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Ai\Services\AiService;
use Juwri\Modules\Menu\Models\AiMenuAction;
use Juwri\Modules\Menu\Models\Menu;
use Mockery;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * C5 — the AI menu-action screen + endpoints. Gating (feature:ai_assistant kill-switch),
 * the propose→approve→applied flow (provider mocked), and blade state rendering.
 */
class AiMenuActionUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class, EnsureStaffDashboardPermission::class]);
    }

    /** @return array{0: User, 1: Store} */
    private function ownerStore(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10,
            'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999, 'ai_assistant' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1',
        ]);

        return [$owner, $store];
    }

    private function item(Store $store): Menu
    {
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => '1']);

        return Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Latte', 'price' => 10.00, 'description' => 'x', 'active' => '1']);
    }

    private function act(User $owner, Store $store)
    {
        return $this->actingAs($owner)->withSession(['dashboard_store_id' => $store->id]);
    }

    // ----------------------------------------------------------------- gating

    public function test_unentitled_is_blocked(): void
    {
        // Platform kill-switch defaults OFF → feature:ai_assistant refuses.
        config(['settings.admin_allow_ai_assistant' => false]);
        [$owner, $store] = $this->ownerStore();

        $this->act($owner, $store)->get(route('ai.menu-actions'))->assertForbidden();
        $this->act($owner, $store)->post(route('ai.menu-actions.propose'), ['prompt' => 'hide x'])->assertForbidden();
    }

    // ------------------------------------------------------ propose→approve flow

    public function test_propose_then_approve_applies_the_change(): void
    {
        config(['settings.admin_allow_ai_assistant' => true]);
        [$owner, $store] = $this->ownerStore();
        $item = $this->item($store);

        // Mock only the provider — the real MenuActionService runs.
        $ai = Mockery::mock(AiService::class);
        $ai->shouldReceive('json')->once()->andReturn([
            'actions' => [
                ['kind' => 'hide_items', 'title' => 'Hide the latte', 'payload' => ['item_ids' => [$item->id]]],
            ],
        ]);
        $this->app->instance(AiService::class, $ai);

        // Propose → a draft row is created.
        $this->act($owner, $store)
            ->post(route('ai.menu-actions.propose'), ['prompt' => 'hide the latte'])
            ->assertRedirect();
        $this->assertDatabaseHas('ai_menu_actions', ['store_id' => $store->id, 'kind' => 'hide_items', 'status' => 'draft']);

        $action = AiMenuAction::where('store_id', $store->id)->firstOrFail();

        // Approve now → applied, and the item is hidden.
        $this->act($owner, $store)
            ->post(route('ai.menu-actions.act', $action->id), ['op' => 'approve'])
            ->assertRedirect();
        $this->assertSame('applied', $action->fresh()->status);
        $this->assertSame('0', (string) $item->fresh()->active);

        // Roll back → item visible again.
        $this->act($owner, $store)
            ->post(route('ai.menu-actions.act', $action->id), ['op' => 'rollback'])
            ->assertRedirect();
        $this->assertSame('rolled_back', $action->fresh()->status);
        $this->assertSame('1', (string) $item->fresh()->active);
    }

    public function test_schedule_sets_run_at_and_recurrence(): void
    {
        config(['settings.admin_allow_ai_assistant' => true]);
        [$owner, $store] = $this->ownerStore();
        $item = $this->item($store);
        $action = AiMenuAction::create([
            'store_id' => $store->id, 'kind' => 'hide_items', 'title' => 'Hide', 'payload' => ['item_ids' => [$item->id]], 'status' => 'draft',
        ]);

        $this->act($owner, $store)
            ->post(route('ai.menu-actions.act', $action->id), ['op' => 'schedule', 'run_at' => '2026-09-01T14:30', 'recurrence' => 'weekly'])
            ->assertRedirect();

        $fresh = $action->fresh();
        $this->assertSame('scheduled', $fresh->status);
        $this->assertSame('weekly', $fresh->recurrence);
        $this->assertSame('2026-09-01 14:30', $fresh->run_at->format('Y-m-d H:i'));
    }

    // ------------------------------------------------------------ render states

    public function test_index_renders_states(): void
    {
        config(['settings.admin_allow_ai_assistant' => true]);
        [$owner, $store] = $this->ownerStore();
        $item = $this->item($store);

        AiMenuAction::create(['store_id' => $store->id, 'kind' => 'price_adjust', 'title' => 'Raise coffee', 'payload' => ['item_ids' => [$item->id], 'percent' => 10], 'status' => 'draft']);
        AiMenuAction::create(['store_id' => $store->id, 'kind' => 'hide_items', 'title' => 'Hidden latte', 'payload' => ['item_ids' => [$item->id]], 'status' => 'applied', 'applied_at' => now(), 'snapshot' => ['items' => [['id' => $item->id, 'active' => '1']]]]);

        $this->act($owner, $store)
            ->get(route('ai.menu-actions'))
            ->assertOk()
            ->assertSee('Raise coffee')        // draft title
            ->assertSee('Approve now')         // draft action button
            ->assertSee('Hidden latte')        // applied title
            ->assertSee('Roll back')           // applied rollback button
            ->assertSee('Before');             // the before/after preview table
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
