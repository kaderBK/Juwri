<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Channels;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Services\FeatureAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Channels\Models\AggregatorConnection;
use Juwri\Modules\Channels\Services\Aggregators\DeliverectProvider;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * D2 (integrations pack): the owner Deliverect panel — location rows, sync,
 * PLU health inline fix, busy toggle, snooze list — HTTP through the controller.
 */
class DeliverectPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->shareLayoutGlobals();
        config(['settings.admin_allow_aggregator_deliverect' => true]);
    }

    /** @return array{0: User, 1: Store} */
    private function owner(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$owner, $store];
    }

    public function test_panel_renders_locations_and_plu_health(): void
    {
        [$owner, $r] = $this->owner();
        AggregatorConnection::create(['provider' => 'deliverect', 'store_id' => $r->id, 'external_store_id' => 'CHLINK-A', 'status' => 'active']);
        Menu::create(['store_id' => $r->id, 'name' => 'Mapped', 'price' => 10, 'active' => 1, 'external_sku' => 'SKU-1']);
        Menu::create(['store_id' => $r->id, 'name' => 'Unmapped', 'price' => 10, 'active' => 1]);

        $res = $this->actingAs($owner)->get(route('integrations.deliverect.index', ['store_id' => $r->id]))->assertStatus(200);
        $res->assertSeeHtml('deliverect-panel')->assertSee('CHLINK-A')->assertSeeHtml('plu-health')->assertSeeHtml('1 / 2'); // one of two items unmapped
    }

    public function test_sync_marks_connection_pending(): void
    {
        [$owner, $r] = $this->owner();
        $conn = AggregatorConnection::create(['provider' => 'deliverect', 'store_id' => $r->id, 'external_store_id' => 'CHLINK-S', 'status' => 'active']);

        $this->actingAs($owner)->post(route('integrations.deliverect.sync', $conn->id).'?store_id='.$r->id)->assertRedirect();
        $this->assertSame('pending', $conn->fresh()->menu_sync_state);
        $this->assertNotNull($conn->fresh()->last_synced_at);
    }

    public function test_set_sku_maps_item(): void
    {
        [$owner, $r] = $this->owner();
        $item = Menu::create(['store_id' => $r->id, 'name' => 'X', 'price' => 10, 'active' => 1]);

        $this->actingAs($owner)->post(route('integrations.deliverect.sku').'?store_id='.$r->id, [
            'menu_id' => $item->id, 'external_sku' => 'PLU-42',
        ])->assertRedirect();
        $this->assertSame('PLU-42', $item->fresh()->external_sku);
    }

    public function test_busy_toggle_persists(): void
    {
        [$owner, $r] = $this->owner();
        $conn = AggregatorConnection::create(['provider' => 'deliverect', 'store_id' => $r->id, 'external_store_id' => 'CHLINK-B', 'status' => 'active']);

        $this->actingAs($owner)->post(route('integrations.deliverect.busy').'?store_id='.$r->id, ['busy' => 1, 'store_id' => $r->id])->assertRedirect();
        $this->assertTrue((bool) ($conn->fresh()->settings['busy'] ?? false));
    }

    public function test_unsnooze_clears_item(): void
    {
        [$owner, $r] = $this->owner();
        $item = Menu::create(['store_id' => $r->id, 'name' => 'Y', 'price' => 10, 'active' => 1]);
        $conn = AggregatorConnection::create(['provider' => 'deliverect', 'store_id' => $r->id, 'external_store_id' => 'CHLINK-U', 'status' => 'active']);
        new DeliverectProvider()->snoozeProduct($conn, $item->id, true);
        $this->assertArrayHasKey((string) $item->id, $conn->fresh()->settings['snoozed_plus']);

        $this->actingAs($owner)->post(route('integrations.deliverect.unsnooze').'?store_id='.$r->id, ['menu_id' => $item->id, 'store_id' => $r->id])->assertRedirect();
        $this->assertArrayNotHasKey((string) $item->id, (array) ($conn->fresh()->settings['snoozed_plus'] ?? []));
    }

    public function test_kill_switch_off_gates_panel(): void
    {
        config(['settings.admin_allow_aggregator_deliverect' => false]);
        FeatureAccess::flush();
        [$owner, $r] = $this->owner();

        $this->actingAs($owner)->get(route('integrations.deliverect.index', ['store_id' => $r->id]))->assertStatus(403);
    }

    public function test_foreign_connection_is_not_syncable(): void
    {
        [$owner, $r] = $this->owner();
        [$other, $otherR] = $this->owner();
        $foreign = AggregatorConnection::create(['provider' => 'deliverect', 'store_id' => $otherR->id, 'external_store_id' => 'CHLINK-F', 'status' => 'active']);

        $this->actingAs($owner)->post(route('integrations.deliverect.sync', $foreign->id).'?store_id='.$r->id)->assertStatus(404);
    }
}
