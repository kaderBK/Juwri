<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Integrations\Models\ApiToken;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Granular per-token abilities (resource:action scopes) and their coverage,
 * including back-compat with legacy read/write tokens.
 */
class ApiScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function context(): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999]),
            'status' => 1,
        ]);
        $user = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);
        $r = Store::create([
            'user_id' => $user->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$user, $r];
    }

    private function token(User $user, array $abilities): string
    {
        $raw = Str::random(40);
        ApiToken::create(['user_id' => $user->id, 'name' => 'T', 'token' => hash('sha256', $raw), 'abilities' => $abilities]);

        return $raw;
    }

    // ── Coverage unit checks ────────────────────────────────────────────────────

    public function test_ability_coverage_matrix(): void
    {
        $this->assertTrue(ApiToken::abilityCovers('*', 'orders:write'));
        $this->assertTrue(ApiToken::abilityCovers('write', 'orders:write'));
        $this->assertTrue(ApiToken::abilityCovers('write', 'menu:read'));   // write ⊃ read
        $this->assertTrue(ApiToken::abilityCovers('read', 'menu:read'));
        $this->assertFalse(ApiToken::abilityCovers('read', 'menu:write'));
        $this->assertTrue(ApiToken::abilityCovers('orders:write', 'orders:read'));
        $this->assertFalse(ApiToken::abilityCovers('orders:write', 'menu:read'));
        $this->assertFalse(ApiToken::abilityCovers('orders:read', 'restaurants:read'));
    }

    // ── HTTP enforcement ────────────────────────────────────────────────────────

    public function test_menu_scoped_token_can_write_menu_only(): void
    {
        [$user, $r] = $this->context();
        $token = $this->token($user, ['menu:write']);

        // Can create a menu item
        $this->withToken($token)
            ->postJson(route('api.v1.menu.items.store', $r->id), ['name' => 'Pie', 'price' => 5])
            ->assertCreated();

        // Cannot read orders (orders:read) or create orders (orders:write)
        $this->withToken($token)->getJson(route('api.v1.stores.orders', $r->id))->assertForbidden();
        $this->withToken($token)->postJson(route('api.v1.orders.store', $r->id), [
            'type' => 'takeaway', 'items' => [['item_id' => 1, 'quantity' => 1]],
        ])->assertForbidden();
    }

    public function test_orders_read_scope_cannot_read_menu_or_write(): void
    {
        [$user, $r] = $this->context();
        $token = $this->token($user, ['orders:read']);

        $this->withToken($token)->getJson(route('api.v1.stores.orders', $r->id))->assertOk();
        $this->withToken($token)->getJson(route('api.v1.stores.menu', $r->id))->assertForbidden();
        $this->withToken($token)->postJson(route('api.v1.menu.items.store', $r->id), ['name' => 'X', 'price' => 1])->assertForbidden();
    }

    public function test_analytics_scope_isolated(): void
    {
        [$user, $r] = $this->context();
        $token = $this->token($user, ['analytics:read']);

        $this->withToken($token)->getJson(route('api.v1.analytics.summary', $r->id))->assertOk();
        $this->withToken($token)->getJson(route('api.v1.stores.orders', $r->id))->assertForbidden();
    }

    public function test_legacy_read_token_still_reads_everything(): void
    {
        [$user, $r] = $this->context();
        Menu::create(['store_id' => $r->id, 'name' => 'A', 'price' => 1, 'active' => 1]);
        $token = $this->token($user, ['read']);

        $this->withToken($token)->getJson(route('api.v1.stores.menu', $r->id))->assertOk();
        $this->withToken($token)->getJson(route('api.v1.stores.orders', $r->id))->assertOk();
        $this->withToken($token)->getJson(route('api.v1.analytics.summary', $r->id))->assertOk();
        // but still no writes
        $this->withToken($token)->postJson(route('api.v1.menu.items.store', $r->id), ['name' => 'X', 'price' => 1])->assertForbidden();
    }
}
