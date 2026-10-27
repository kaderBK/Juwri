<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Ingredient;
use App\Models\Order;
use App\Services\DashboardAttentionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\Locale;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Models\UserOption;
use Juwri\Modules\Channels\Models\AggregatorConnection;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Payments\Models\Dispute;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P2-2 — DashboardAttentionService: one exception row per problem type, store-scoped,
 * and the dashboard widget that renders (and hides when all clear).
 */
class DashboardAttentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        View::share([
            'activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true),
            'settings' => (object) [], 'languages' => collect(), 'admin_languages' => collect(),
        ]);
        $this->withoutMiddleware([
            Installed::class,
            Locale::class,
            PreventRequestForgery::class,
        ]);
    }

    private function owner(): User
    {
        $plan = TestPlans::create(['name' => 'P '.Str::random(6), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]), 'status' => 1]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function store(User $o): Store
    {
        $t = 'R '.Str::random(6);

        return Store::create(['user_id' => $o->id, 'slug' => Str::slug($t).'-'.Str::lower(Str::random(5)), 'color' => '#111', 'title' => $t, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_all_clear_returns_no_exceptions(): void
    {
        $o = $this->owner();
        $s = $this->store($o);
        $this->assertSame([], DashboardAttentionService::for([$s->id]));
    }

    public function test_each_exception_type_is_detected_and_store_scoped(): void
    {
        $o = $this->owner();
        $a = $this->store($o);
        $b = $this->store($o);

        // Stuck order (unpaid, older than the threshold) on A.
        Order::insert(['store_id' => $a->id, 'status' => 'pending', 'is_paid' => 0, 'type' => 'delivery', 'customer_name' => 'C', 'phone_number' => '1', 'created_at' => Carbon::now()->subHours(5)]);
        // A fresh unpaid order must NOT count (under the threshold).
        Order::insert(['store_id' => $a->id, 'status' => 'pending', 'is_paid' => 0, 'type' => 'delivery', 'customer_name' => 'D', 'phone_number' => '1', 'created_at' => Carbon::now()]);
        // Offline channel + open dispute + low stock on A.
        AggregatorConnection::create(['provider' => 'ubereats', 'store_id' => $a->id, 'external_store_id' => 'ext1', 'status' => 'error', 'sync_error' => 'boom']);
        Dispute::create(['store_id' => $a->id, 'gateway' => 'stripe', 'external_id' => 'x', 'amount' => 10, 'currency' => 'USD', 'status' => 'needs_response', 'opened_at' => now()]);
        Ingredient::create(['store_id' => $a->id, 'name' => 'Flour', 'unit' => 'kg', 'current_stock' => 1, 'par_level' => 10, 'active' => 1, 'position' => 1]);
        // Store B noise (must not leak into A's scope).
        Dispute::create(['store_id' => $b->id, 'gateway' => 'stripe', 'external_id' => 'y', 'amount' => 10, 'currency' => 'USD', 'status' => 'needs_response', 'opened_at' => now()]);

        $ex = collect(DashboardAttentionService::for([$a->id]));
        $types = $ex->pluck('type')->all();

        $this->assertContains('orders', $types);
        $this->assertContains('channels', $types);
        $this->assertContains('disputes', $types);
        $this->assertContains('stock', $types);
        $this->assertEquals(1, $ex->firstWhere('type', 'orders')['count']); // fresh order excluded
        $this->assertEquals(1, $ex->firstWhere('type', 'disputes')['count']); // B's dispute excluded
    }

    /** countsByStore() matches count(for()) per store and runs one query per check, not per store. */
    public function test_counts_by_store_matches_and_batches(): void
    {
        $o = $this->owner();
        $a = $this->store($o);
        $b = $this->store($o);

        // A: four categories (stuck order, offline channel, dispute, low ingredient).
        Order::insert(['store_id' => $a->id, 'status' => 'pending', 'is_paid' => 0, 'type' => 'delivery', 'customer_name' => 'C', 'phone_number' => '1', 'created_at' => Carbon::now()->subHours(5)]);
        AggregatorConnection::create(['provider' => 'ubereats', 'store_id' => $a->id, 'external_store_id' => 'ext1', 'status' => 'error', 'sync_error' => 'boom']);
        Dispute::create(['store_id' => $a->id, 'gateway' => 'stripe', 'external_id' => 'x', 'amount' => 10, 'currency' => 'USD', 'status' => 'needs_response', 'opened_at' => now()]);
        Ingredient::create(['store_id' => $a->id, 'name' => 'Flour', 'unit' => 'kg', 'current_stock' => 1, 'par_level' => 10, 'active' => 1, 'position' => 1]);
        // B: one category (a dispute).
        Dispute::create(['store_id' => $b->id, 'gateway' => 'stripe', 'external_id' => 'y', 'amount' => 10, 'currency' => 'USD', 'status' => 'needs_response', 'opened_at' => now()]);

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        $counts = DashboardAttentionService::countsByStore([$a->id, $b->id]);

        // Exactly one grouped query per check — flat regardless of store count.
        $this->assertSame(8, $queries, "countsByStore must run 8 grouped queries (one per check), not 8 per store; got {$queries}");

        // Per-store totals equal count(for([store])).
        $this->assertSame(count(DashboardAttentionService::for([$a->id])), $counts[$a->id] ?? 0);
        $this->assertSame(count(DashboardAttentionService::for([$b->id])), $counts[$b->id] ?? 0);
        $this->assertSame(4, $counts[$a->id]);
        $this->assertSame(1, $counts[$b->id]);
    }

    public function test_widget_renders_and_hides_via_customize(): void
    {
        $o = $this->owner();
        $a = $this->store($o);
        Dispute::create(['store_id' => $a->id, 'gateway' => 'stripe', 'external_id' => 'z', 'amount' => 10, 'currency' => 'USD', 'status' => 'needs_response', 'opened_at' => now()]);

        $this->actingAs($o)->withSession(['dashboard_store_id' => $a->id])
            ->get(route('dashboard'))->assertStatus(200)->assertSeeHtml(___('Needs attention'));

        // Hide it via the customize/layout endpoint.
        UserOption::updateUserOption($o->id, 'dashboard_hidden_cards', json_encode(['attention']));
        $this->actingAs($o)->withSession(['dashboard_store_id' => $a->id])
            ->get(route('dashboard'))->assertStatus(200)->assertDontSeeHtml('Needs attention');
    }

    // ── D7: low-stock + sold-out menu items ──────────────────────────

    private function item(Store $p, array $attrs = []): Menu
    {
        return Menu::create(array_merge([
            'store_id' => $p->id, 'name' => 'Item '.Str::random(4), 'price' => 5, 'active' => 1,
        ], $attrs));
    }

    public function test_low_stock_menu_item_surfaces_a_row(): void
    {
        $o = $this->owner();
        $p = $this->store($o);
        $this->item($p, ['track_inventory' => 1, 'stock_qty' => 2, 'low_stock_threshold' => 5]);

        $rows = collect(DashboardAttentionService::for([$p->id]));
        $this->assertTrue($rows->contains('type', 'menu_stock'));
    }

    public function test_sold_out_row_has_un86_action_and_route_clears_flag(): void
    {
        $o = $this->owner();
        $p = $this->store($o);
        $m = $this->item($p, ['is_sold_out' => 1]);

        $rows = collect(DashboardAttentionService::for([$p->id]));
        $soldOut = $rows->firstWhere('type', 'menu_soldout');
        $this->assertNotNull($soldOut);
        $this->assertArrayHasKey('action_url', $soldOut);

        // The un-86 route clears the flag, ownership-guarded.
        $this->actingAs($o)->post(route('stores.menu.un86', $p->id))
            ->assertStatus(302);
        $this->assertSame(0, (int) $m->fresh()->is_sold_out);
    }

    public function test_un86_is_ownership_scoped(): void
    {
        $o = $this->owner();
        $p = $this->store($o);
        $m = $this->item($p, ['is_sold_out' => 1]);

        $attacker = $this->owner();
        $this->actingAs($attacker)->post(route('stores.menu.un86', $p->id))->assertNotFound();
        $this->assertSame(1, (int) $m->fresh()->is_sold_out); // untouched
    }
}
