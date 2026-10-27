<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Juwri\Core\Jobs\CreateNotificationJob;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Ai\Services\AiService;
use Juwri\Modules\Menu\Models\AiMenuAction;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Services\Ai\MenuActionService;
use Mockery;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * C4 — the AI menu-action engine: propose (validated draft rows), apply/rollback round-trip
 * per kind, idempotence, and the failure notification. The provider is mocked throughout —
 * no live HTTP.
 */
class AiMenuActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    private function store(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10,
            'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999, 'ai_assistant' => '1'],
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

    private function item(Store $store, MenuCategory $cat, float $price = 10.0, string $desc = 'old desc'): Menu
    {
        return Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Dish '.Str::random(4),
            'price' => $price, 'description' => $desc, 'active' => '1',
        ]);
    }

    private function category(Store $store): MenuCategory
    {
        return MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => '1']);
    }

    private function service(?AiService $ai = null): MenuActionService
    {
        return new MenuActionService($ai ?? app(AiService::class));
    }

    // --------------------------------------------------------------- propose

    public function test_propose_creates_draft_rows_from_a_valid_proposal(): void
    {
        $store = $this->store();
        $cat = $this->category($store);
        $item = $this->item($store, $cat);

        $ai = Mockery::mock(AiService::class);
        $ai->shouldReceive('json')->once()->andReturn([
            'actions' => [
                ['kind' => 'hide_items', 'title' => 'Hide the special', 'payload' => ['item_ids' => [$item->id]]],
            ],
        ]);

        $created = $this->service($ai)->propose($store->id, 'hide the special');

        $this->assertCount(1, $created);
        $this->assertDatabaseHas('ai_menu_actions', [
            'store_id' => $store->id, 'kind' => 'hide_items', 'status' => 'draft',
        ]);
    }

    // ------------------------------------------------------------ validator

    public function test_validator_rejects_a_bad_kind(): void
    {
        $store = $this->store();
        $cat = $this->category($store);
        $item = $this->item($store, $cat);

        $ok = $this->service()->validateProposal([
            'actions' => [['kind' => 'delete_everything', 'title' => 'x', 'payload' => ['item_ids' => [$item->id]]]],
        ], $store->id);

        $this->assertFalse($ok);
    }

    public function test_validator_rejects_foreign_item_ids(): void
    {
        $store = $this->store();
        $other = $this->store();
        $cat = $this->category($other);
        $foreign = $this->item($other, $cat); // belongs to a DIFFERENT store

        $ok = $this->service()->validateProposal([
            'actions' => [['kind' => 'hide_items', 'title' => 'x', 'payload' => ['item_ids' => [$foreign->id]]]],
        ], $store->id);

        $this->assertFalse($ok);
    }

    public function test_validator_rejects_over_cap_price_move(): void
    {
        $store = $this->store();
        $cat = $this->category($store);
        $item = $this->item($store, $cat);

        $svc = $this->service();
        $this->assertTrue($svc->validateProposal([
            'actions' => [['kind' => 'price_adjust', 'title' => 'x', 'payload' => ['item_ids' => [$item->id], 'percent' => -20]]],
        ], $store->id));
        $this->assertFalse($svc->validateProposal([
            'actions' => [['kind' => 'price_adjust', 'title' => 'x', 'payload' => ['item_ids' => [$item->id], 'percent' => 75]]],
        ], $store->id), 'a >50% price move must be rejected');
    }

    // ------------------------------------------------------- apply/rollback

    private function draft(Store $store, string $kind, array $payload): AiMenuAction
    {
        return AiMenuAction::create([
            'store_id' => $store->id, 'kind' => $kind, 'title' => ucfirst($kind),
            'payload' => $payload, 'status' => 'draft',
        ]);
    }

    public function test_hide_items_apply_and_rollback(): void
    {
        $store = $this->store();
        $cat = $this->category($store);
        $item = $this->item($store, $cat);

        $action = $this->draft($store, 'hide_items', ['item_ids' => [$item->id]]);
        $this->service()->apply($action);

        $this->assertSame('0', (string) $item->fresh()->active);
        $this->assertSame('applied', $action->fresh()->status);
        $this->assertNotNull($action->fresh()->applied_at);

        $this->service()->rollback($action->fresh());
        $this->assertSame('1', (string) $item->fresh()->active);
        $this->assertSame('rolled_back', $action->fresh()->status);
    }

    public function test_price_adjust_apply_and_rollback(): void
    {
        $store = $this->store();
        $cat = $this->category($store);
        $item = $this->item($store, $cat, 10.00);

        $action = $this->draft($store, 'price_adjust', ['item_ids' => [$item->id], 'percent' => -20]);
        $this->service()->apply($action);
        $this->assertEquals(8.00, (float) $item->fresh()->price);

        $this->service()->rollback($action->fresh());
        $this->assertEquals(10.00, (float) $item->fresh()->price);
    }

    public function test_update_descriptions_apply_and_rollback(): void
    {
        $store = $this->store();
        $cat = $this->category($store);
        $item = $this->item($store, $cat, 10.0, 'old desc');

        $action = $this->draft($store, 'update_descriptions', ['items' => [['id' => $item->id, 'description' => 'fresh desc']]]);
        $this->service()->apply($action);
        $this->assertSame('fresh desc', (string) $item->fresh()->description);

        $this->service()->rollback($action->fresh());
        $this->assertSame('old desc', (string) $item->fresh()->description);
    }

    public function test_toggle_category_apply_and_rollback(): void
    {
        $store = $this->store();
        $cat = $this->category($store);

        $action = $this->draft($store, 'toggle_category', ['category_id' => $cat->id, 'active' => false]);
        $this->service()->apply($action);
        $this->assertSame('0', (string) $cat->fresh()->active);

        $this->service()->rollback($action->fresh());
        $this->assertSame('1', (string) $cat->fresh()->active);
    }

    public function test_apply_and_rollback_are_idempotent(): void
    {
        $store = $this->store();
        $cat = $this->category($store);
        $item = $this->item($store, $cat, 10.00);

        $action = $this->draft($store, 'price_adjust', ['item_ids' => [$item->id], 'percent' => -10]);
        $svc = $this->service();
        $svc->apply($action);
        $svc->apply($action->fresh()); // second apply is a no-op — price must not move again
        $this->assertEquals(9.00, (float) $item->fresh()->price);

        $svc->rollback($action->fresh());
        $svc->rollback($action->fresh()); // second rollback is a no-op
        $this->assertEquals(10.00, (float) $item->fresh()->price);
        $this->assertSame('rolled_back', $action->fresh()->status);
    }

    public function test_rollback_only_applies_to_applied_actions(): void
    {
        $store = $this->store();
        $cat = $this->category($store);
        $item = $this->item($store, $cat);

        $action = $this->draft($store, 'hide_items', ['item_ids' => [$item->id]]);
        // A draft cannot be rolled back — status stays draft, item untouched.
        $this->service()->rollback($action);
        $this->assertSame('draft', $action->fresh()->status);
        $this->assertSame('1', (string) $item->fresh()->active);
    }

    public function test_failure_marks_failed_and_notifies(): void
    {
        Bus::fake();
        $store = $this->store();
        $action = $this->draft($store, 'hide_items', ['item_ids' => [1]]);

        // Drive the failure path directly (markFailed is the shared failure seam).
        $m = new \ReflectionMethod(MenuActionService::class, 'markFailed');
        $m->setAccessible(true);
        $m->invoke($this->service(), $action, new \RuntimeException('boom'));

        $this->assertSame('failed', $action->fresh()->status);
        $this->assertStringContainsString('boom', (string) $action->fresh()->error);
        Bus::assertDispatched(CreateNotificationJob::class, fn ($j) => $j->type === 'ai_action');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
