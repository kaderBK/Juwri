<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Ai\Services\AiService;
use Juwri\Modules\Menu\Models\AiMenuAction;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Services\Ai\MenuActionService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * C6 — the ai:run-menu-actions runner: due selection, recurrence clone-forward, row-isolated
 * failure, and idempotent re-run. No AI is called (apply/rollback are pure mutation).
 */
class AiRunMenuActionsTest extends TestCase
{
    use RefreshDatabase;

    private function store(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10,
            'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'x', 'description' => 'x',
            'main_image' => 'a.png', 'cover_image' => 'a.png', 'address' => '1 St', 'phone' => '1',
        ]);
    }

    private function item(Store $store, float $price = 10.0): Menu
    {
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => '1']);

        return Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Dish', 'price' => $price, 'description' => 'x', 'active' => '1']);
    }

    private function scheduled(Store $store, Menu $item, $runAt, ?string $recurrence = null, string $kind = 'hide_items', ?array $payload = null): AiMenuAction
    {
        return AiMenuAction::create([
            'store_id' => $store->id, 'kind' => $kind, 'title' => ucfirst($kind),
            'payload' => $payload ?? ['item_ids' => [$item->id]],
            'status' => 'scheduled', 'run_at' => $runAt, 'recurrence' => $recurrence,
        ]);
    }

    public function test_due_selection_applies_only_past_scheduled_rows(): void
    {
        $store = $this->store();
        $item = $this->item($store);

        $due = $this->scheduled($store, $item, now()->subMinutes(5));
        $future = $this->scheduled($store, $item, now()->addDay());
        $draft = AiMenuAction::create(['store_id' => $store->id, 'kind' => 'hide_items', 'title' => 'Draft', 'payload' => ['item_ids' => [$item->id]], 'status' => 'draft']);

        $this->artisan('ai:run-menu-actions')->assertExitCode(0);

        $this->assertSame('applied', $due->fresh()->status);
        $this->assertSame('scheduled', $future->fresh()->status);
        $this->assertSame('draft', $draft->fresh()->status);
    }

    public function test_recurring_action_is_cloned_forward(): void
    {
        $store = $this->store();
        $item = $this->item($store);
        $runAt = now()->subMinutes(2)->startOfMinute();

        $daily = $this->scheduled($store, $item, $runAt, 'daily');

        $this->artisan('ai:run-menu-actions')->assertExitCode(0);

        // Original applied, a fresh scheduled clone exists one day later.
        $this->assertSame('applied', $daily->fresh()->status);
        $clone = AiMenuAction::where('store_id', $store->id)
            ->where('status', 'scheduled')
            ->where('id', '!=', $daily->id)
            ->first();
        $this->assertNotNull($clone, 'a recurring action must clone forward');
        $this->assertSame('daily', $clone->recurrence);
        $this->assertSame($runAt->copy()->addDay()->format('Y-m-d H:i'), $clone->run_at->format('Y-m-d H:i'));
    }

    /**
     * HPMS §8-P1: after scheduler downtime a recurring action must apply ONCE and
     * re-arm to a FUTURE run_at — not clone a still-past row that the next tick
     * re-applies immediately, compounding e.g. a price cut across the whole backlog.
     */
    public function test_recurring_rearm_skips_missed_occurrences_after_downtime(): void
    {
        $store = $this->store();
        $item = $this->item($store);
        $runAt = now()->subDays(5)->startOfMinute(); // 5 missed daily cycles

        $daily = $this->scheduled($store, $item, $runAt, 'daily');

        $this->artisan('ai:run-menu-actions')->assertExitCode(0);

        $this->assertSame('applied', $daily->fresh()->status);
        // Exactly one application — no backlog compounding.
        $this->assertSame(1, AiMenuAction::where('store_id', $store->id)->where('status', 'applied')->count());

        $clone = AiMenuAction::where('store_id', $store->id)
            ->where('status', 'scheduled')->where('id', '!=', $daily->id)->first();
        $this->assertNotNull($clone);
        $this->assertTrue($clone->run_at->isFuture(), 'the re-armed occurrence must be in the future, not a replay of the backlog');
    }

    public function test_failure_in_one_row_does_not_abort_the_batch(): void
    {
        $store = $this->store();
        $good = $this->item($store);

        // BOOM first (earlier run_at), then a valid one.
        $boom = $this->scheduled($store, $good, now()->subMinutes(10));
        $boom->update(['title' => 'BOOM']);
        $ok = $this->scheduled($store, $good, now()->subMinutes(1));

        // A service double that throws for the BOOM row but delegates otherwise.
        $svc = new class(app(AiService::class)) extends MenuActionService
        {
            public array $seen = [];

            public function apply(AiMenuAction $action): AiMenuAction
            {
                $this->seen[] = $action->id;
                if (str_contains((string) $action->title, 'BOOM')) {
                    throw new \RuntimeException('boom');
                }

                return parent::apply($action);
            }
        };
        $this->app->instance(MenuActionService::class, $svc);

        $this->artisan('ai:run-menu-actions')->assertExitCode(0);

        // Both rows were reached (the throw did not abort the loop), and the good one applied.
        $this->assertContains($boom->id, $svc->seen);
        $this->assertContains($ok->id, $svc->seen);
        $this->assertSame('applied', $ok->fresh()->status);
    }

    public function test_rerun_is_idempotent(): void
    {
        $store = $this->store();
        $item = $this->item($store, 10.00);
        $runAt = now()->subMinutes(2)->startOfMinute();

        $this->scheduled($store, $item, $runAt, 'daily', 'price_adjust', ['item_ids' => [$item->id], 'percent' => -10]);

        $this->artisan('ai:run-menu-actions')->assertExitCode(0);
        $this->artisan('ai:run-menu-actions')->assertExitCode(0);

        // Price moved exactly once (10 → 9), not twice; exactly one applied + one future clone.
        $this->assertEquals(9.00, (float) $item->fresh()->price);
        $this->assertSame(1, AiMenuAction::where('store_id', $store->id)->where('status', 'applied')->count());
        $this->assertSame(1, AiMenuAction::where('store_id', $store->id)->where('status', 'scheduled')->count());
    }
}
