<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Menu;

use App\Models\MenuCategory;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Integrations\Jobs\DeliverWebhookJob;
use Juwri\Modules\Menu\Models\Menu;
use Tests\TestCase;

/** MCP-expansion P0-4: menu.updated webhook fires from the model write choke point. */
class MenuWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([DeliverWebhookJob::class]);
    }

    private function ownerWithHook(): User
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        WebhookEndpoint::create(['user_id' => $owner->id, 'url' => 'https://hooks.test/x', 'secret' => 'whsec_x', 'active' => true, 'events' => ['menu.updated']]);

        return $owner;
    }

    private function storeFixture(User $owner): Store
    {
        $title = 'R '.Str::random(5);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_item_update_fires_menu_updated(): void
    {
        $r = $this->storeFixture($this->ownerWithHook());
        $item = Menu::create(['store_id' => $r->id, 'name' => 'Pizza', 'price' => 12, 'active' => 1]);
        Bus::fake([DeliverWebhookJob::class]); // reset so we only assert the update

        $item->update(['price' => 15]);

        Bus::assertDispatched(DeliverWebhookJob::class, function ($job) use ($r, $item) {
            return $job->event === 'menu.updated'
                && $job->payload['change'] === 'updated'
                && $job->payload['entity'] === 'item'
                && $job->payload['store_id'] === $r->id
                && $job->payload['id'] === $item->id;
        });
    }

    public function test_item_create_and_delete_fire_with_correct_change(): void
    {
        $r = $this->storeFixture($this->ownerWithHook());
        $item = Menu::create(['store_id' => $r->id, 'name' => 'Soda', 'price' => 2, 'active' => 1]);
        Bus::assertDispatched(DeliverWebhookJob::class, fn ($job) => $job->event === 'menu.updated' && $job->payload['change'] === 'created');

        $item->delete();
        Bus::assertDispatched(DeliverWebhookJob::class, fn ($job) => $job->event === 'menu.updated' && $job->payload['change'] === 'deleted');
    }

    public function test_category_write_fires_with_category_entity(): void
    {
        $r = $this->storeFixture($this->ownerWithHook());
        MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => 1]);

        Bus::assertDispatched(DeliverWebhookJob::class, fn ($job) => $job->event === 'menu.updated' && $job->payload['entity'] === 'category' && $job->payload['change'] === 'created');
    }

    public function test_no_dispatch_when_owner_has_no_matching_endpoint(): void
    {
        // owner with NO endpoint at all
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $r = $this->storeFixture($owner);
        Menu::create(['store_id' => $r->id, 'name' => 'Water', 'price' => 1, 'active' => 1]);

        Bus::assertNotDispatched(DeliverWebhookJob::class);
    }

    // Cleanup: order-driven stock deduction must NOT fire menu.updated (order.*
    // events cover it), but deliberate edits still do.
    public function test_stock_deduction_is_suppressed_but_edits_still_fire(): void
    {
        $r = $this->storeFixture($this->ownerWithHook());
        $item = Menu::create(['store_id' => $r->id, 'name' => 'Burger', 'price' => 9, 'active' => 1, 'track_inventory' => 1, 'stock_qty' => 10]);
        Bus::fake([DeliverWebhookJob::class]); // reset after create

        Menu::deductStock($item->id, 3);
        Bus::assertNotDispatched(DeliverWebhookJob::class);
        $this->assertSame(7, (int) $item->fresh()->stock_qty, 'stock still deducted');

        // a deliberate edit right after must still fire (suppression was scoped)
        $item->update(['price' => 11]);
        Bus::assertDispatched(DeliverWebhookJob::class, fn ($job) => $job->event === 'menu.updated');
    }
}
