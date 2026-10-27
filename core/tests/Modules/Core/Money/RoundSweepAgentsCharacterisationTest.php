<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Money;

use App\Http\Middleware\Installed;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use App\Services\Agents\AgentToolsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Integrations\Models\ApiToken;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P1-05 slice 10b-3 (characterisation): the bare round() sweep in the agent tools (validateCart quote) and the owner
 * MCP server (menu price writes) in 0-, 2- and 3-decimal stores. Pinned BEFORE the switch to Money::round(); the
 * 3-decimal quote and the price writes then moved to the store currency's decimals (assertions marked 10b-3 change).
 */
class RoundSweepAgentsCharacterisationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(Installed::class);
    }

    /** @return array{0: Store, 1: Menu, 2: User} */
    private function store(string $currency, float $price): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'ai_chatbot' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'ag-'.Str::lower(Str::random(8)),
            'color' => '#111', 'title' => 'R', 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($r->id, 'currency_code', $currency);
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => 1]);
        $item = Menu::create(['store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Pizza', 'price' => $price, 'active' => 1]);

        return [$r, $item, $owner];
    }

    public function test_validate_cart_quote_rounding(): void
    {
        [$r, $item] = $this->store('USD', 12.125); // stored 12.13
        $cart = AgentToolsService::validateCart($r, [['item_id' => $item->id, 'quantity' => 3]]);
        $this->assertSame(36.39, $cart['lines'][0]['line_total']);
        $this->assertSame(36.39, $cart['subtotal']);
        $this->assertSame(36.39, $cart['total']);
        $this->assertSame(0.0, $cart['discount']);

        [$r, $item] = $this->store('JPY', 1250);
        $cart = AgentToolsService::validateCart($r, [['item_id' => $item->id, 'quantity' => 3]]);
        $this->assertSame(3750.0, $cart['subtotal']);
        $this->assertSame(3750.0, $cart['total']);

        [$r, $item] = $this->store('KWD', 1.125);
        $this->assertSame(1.125, (float) $item->fresh()->price);
        $cart = AgentToolsService::validateCart($r, [['item_id' => $item->id, 'quantity' => 3]]);
        // 10b-3 change: before the sweep the KWD quote was cut to 2 decimals (3.38) while the order totals 3.375.
        $this->assertSame(3.375, $cart['lines'][0]['line_total']);
        $this->assertSame(3.375, $cart['subtotal']);
        $this->assertSame(3.375, $cart['total']);
    }

    public function test_mcp_menu_price_writes(): void
    {
        // 10b-3 change: before the sweep the price was cut to 2 decimals first — KWD 4.125 → 4.130 (4130 fils) and
        // JPY 1234.495 → 1234.50 → 1235 yen; it now rounds once, in the store currency's decimals.
        foreach ([['USD', 4.125, 413], ['KWD', 4.125, 4125], ['JPY', 1234.375, 1234], ['JPY', 1234.495, 1234]] as [$cur, $price, $minor]) {
            [$r, , $owner] = $this->store($cur, 1);
            $raw = Str::random(40);
            ApiToken::create(['user_id' => $owner->id, 'name' => 't', 'token' => hash('sha256', $raw), 'abilities' => ['read', 'write']]);
            $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [
                'name' => 'add_menu_item', 'arguments' => ['store_id' => $r->id, 'name' => 'Latte '.$cur, 'price' => $price],
            ]], ['Authorization' => 'Bearer '.$raw])->assertOk();
            $this->assertSame($minor, (int) Menu::where('store_id', $r->id)->where('name', 'Latte '.$cur)->value('price_minor'), $cur.' '.$price);
        }
    }
}
