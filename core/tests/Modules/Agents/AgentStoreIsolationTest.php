<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use App\Models\Coupon;
use App\Models\Customer;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Services\Agents\AgentChatService;
use App\Services\FeatureAccess;
use App\Services\OrderApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Agents\Models\AgentConversation;
use Juwri\Modules\Agents\Tools\AgentTool;
use Juwri\Modules\Agents\Tools\AgentToolRegistry;
use Juwri\Modules\Agents\Tools\StorefrontTools;
use Juwri\Modules\Agents\Tools\ToolContext;
use Juwri\Modules\Agents\Tools\ToolError;
use Juwri\Modules\Ai\Contracts\Ai;
use Juwri\Modules\Ai\Services\AiService;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * P4-11: an agent's actions stay inside its own store. Every owner tool and every storefront tool is called with another
 * account's ids (store, order, menu item, category, customer, coupon, order token): each call is refused and NOTHING changes
 * in the other store (whole-table snapshots before / after) or in the caller's own.
 */
class AgentStoreIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'B-SECRET-DISH';

    protected function setUp(): void
    {
        parent::setUp();
        config(['settings.admin_allow_crm_suite' => 1]);
        FeatureAccess::flush();
    }

    /** @return array{user: User, store: Store, cat: MenuCategory, item: Menu, order: Order, customer: Customer, coupon: Coupon} */
    private function account(string $dish): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'ai_chatbot' => '1', 'agent_api' => '1', 'storefront_api' => '1'], 'status' => 1,
        ]);
        $user = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $user->id, 'slug' => Str::slug('r'.Str::random(6)), 'color' => '#111', 'title' => 'Resto '.Str::random(3),
            'sub_title' => 'x', 'description' => 'x', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1', 'phone' => '1',
        ]);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains '.$dish, 'active' => 1]);
        $item = Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => $dish, 'price' => 30, 'active' => 1]);
        $order = OrderApiService::create($user->id, $store->id, ['type' => 'takeaway', 'items' => [['item_id' => $item->id, 'quantity' => 1]]]);
        $customer = Customer::create(['store_id' => $store->id, 'name' => 'Cust '.$dish, 'email' => Str::random(5).'@example.test', 'phone' => UniquePhone::next('+1650555')]);
        $coupon = Coupon::create(['code' => 'C'.strtoupper(Str::random(6)), 'coupon_type' => 'fixed', 'percentage' => 5, 'limit' => 10, 'used' => 0, 'user_id' => $user->id]);

        return compact('user', 'store', 'cat', 'item', 'order', 'customer', 'coupon');
    }

    /** Whole-table fingerprint of everything an agent tool could touch. */
    private function snapshot(): string
    {
        $tables = [(new Menu)->getTable(), (new MenuCategory)->getTable(), (new Order)->getTable(), (new Customer)->getTable(), (new Coupon)->getTable(), 'stores', 'store_options', 'order_items', 'customer_wallets'];
        $tables = array_values(array_filter($tables, fn ($t) => Schema::hasTable($t)));
        $out = [];
        foreach ($tables as $t) {
            $out[$t] = DB::table($t)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        }

        return md5(json_encode($out));
    }

    /** @param array<string, mixed> $schema */
    private function argsFor(array $schema, array $ids): array
    {
        $args = [];
        $props = (array) ($schema['properties'] ?? []);
        foreach (array_keys($props) as $name) {
            if (array_key_exists($name, $ids)) {
                $args[$name] = $ids[$name];
            }
        }
        foreach ((array) ($schema['required'] ?? []) as $name) {
            if (array_key_exists($name, $args)) {
                continue;
            }
            $type = ((array) $props[$name])['type'] ?? 'string';
            $args[$name] = match ($type) {
                'integer', 'number' => 1,
                'boolean' => true,
                'array' => [],
                'object' => [],
                default => $name === 'status' ? 'preparing' : ($name === 'coupon_type' ? 'fixed' : 'x'),
            };
        }

        return $args;
    }

    public function test_every_owner_tool_refuses_another_accounts_ids_and_changes_nothing(): void
    {
        $a = $this->account('A-dish');
        $b = $this->account(self::SECRET);
        $registry = AgentToolRegistry::withDefaults();
        $context = ToolContext::forOwner($a['user']->id, AgentTool::CHANNEL_MCP, fn (string $ability): bool => true);
        $foreign = ['order_id' => $b['order']->id, 'item_id' => $b['item']->id, 'category_id' => $b['cat']->id, 'customer_id' => $b['customer']->id, 'coupon_id' => $b['coupon']->id];
        $swept = 0;

        foreach ($registry->available(AgentTool::OWNER, $context) as $name => $tool) {
            $schema = json_decode(json_encode($tool->inputSchema), true);
            $props = array_keys((array) ($schema['properties'] ?? []));
            $idProps = array_values(array_intersect($props, array_merge(['store_id'], array_keys($foreign))));
            if ($idProps === []) {
                continue; // list_restaurants, create_coupon...: nothing names another account's id
            }
            foreach ([['store_id' => $b['store']->id] + $foreign, ['store_id' => $a['store']->id] + $foreign] as $variant => $ids) {
                if ($variant === 1 && ! array_intersect($props, array_keys($foreign))) {
                    continue; // only a store_id to name: already covered by variant 0
                }
                $before = $this->snapshot();
                $refused = false;
                $output = '';
                try {
                    $output = json_encode($registry->call(AgentTool::OWNER, $name, $this->argsFor($schema, $ids), $context));
                } catch (ToolError) {
                    $refused = true;
                }
                $label = "{$name} (variant {$variant})";
                // A write must be refused. A read may also answer with an empty, filtered list (a filter by an id that is not yours
                // matches nothing) as long as no trace of the other account is in it.
                if (! ($tool->annotations['readOnlyHint'] ?? false)) {
                    $this->assertTrue($refused, "{$label} writes: it must refuse another account's ids, it answered: ".substr((string) $output, 0, 200));
                }
                $this->assertSame($before, $this->snapshot(), "{$label} changed data");
                foreach ([self::SECRET, (string) $b['store']->slug, (string) $b['customer']->email, (string) $b['customer']->phone, (string) $b['coupon']->code] as $trace) {
                    $this->assertStringNotContainsString($trace, (string) $output, "{$label} leaked '{$trace}' of the other account");
                }
                $swept++;
            }
        }
        $this->assertGreaterThan(20, $swept, 'the sweep reached the id-taking owner tools');

        // and the read tools that take no id never list the other account
        $listed = $registry->call(AgentTool::OWNER, 'list_restaurants', [], $context);
        $this->assertStringNotContainsString((string) $b['store']->slug, json_encode($listed));
        $coupons = $registry->call(AgentTool::OWNER, 'list_coupons', [], $context);
        $this->assertStringNotContainsString((string) $b['coupon']->code, json_encode($coupons));
    }

    public function test_storefront_tools_bound_to_one_store_refuse_another_stores_items_orders_and_customers(): void
    {
        $a = $this->account('A-dish');
        $b = $this->account(self::SECRET);
        $before = $this->snapshot();
        $b['order']->refresh();
        $bToken = (string) ($b['order']->track_token ?? $b['order']->getAttribute('token') ?? '');
        $cart = [['id' => $b['item']->id, 'quantity' => 1]];
        $cartItems = [['item_id' => $b['item']->id, 'quantity' => 1], ['id' => $b['item']->id, 'quantity' => 1]];

        $calls = [
            ['get_item', ['item_id' => $b['item']->id]],
            ['validate_cart', ['items' => $cart]],
            ['create_order', ['items' => $cart, 'type' => 'takeaway', 'customer_name' => 'Mallory', 'phone' => $b['customer']->phone]],
            ['create_order', ['items' => $cartItems, 'type' => 'takeaway', 'customer_name' => 'Mallory']],
            ['suggest_upsell', ['cart_item_ids' => [$b['item']->id]]],
            ['dietary_answer', ['item_id' => $b['item']->id, 'tag' => 'vegan']],
            ['order_status', ['track_token' => $bToken !== '' ? $bToken : 'nope']],
            ['order_history', ['phone' => $b['customer']->phone]],
            ['reorder_last', ['phone' => $b['customer']->phone]],
            ['store_credit_balance', ['phone' => $b['customer']->phone]],
        ];
        foreach ($calls as [$tool, $args]) {
            $out = json_encode(AgentChatService::dispatchTool($a['store'], $tool, $args)); // the chat channel: no trusted phone
            $this->assertStringNotContainsString(self::SECRET, $out, "{$tool}: leaked the other store's dish");
            $this->assertStringNotContainsString((string) $b['customer']->phone, $out, "{$tool}: leaked the other store's customer");
            $this->assertDoesNotMatchRegularExpression('/"order_id"\s*:\s*\d+|"ok"\s*:\s*true/', $out, "{$tool}: an order was accepted: {$out}");
        }

        $this->assertSame(1, Order::where('store_id', $b['store']->id)->count(), 'no order was added to the other store');
        $this->assertSame(1, Order::where('store_id', $a['store']->id)->count(), 'and none to the agent\'s own store');
        $this->assertSame($before, $this->snapshot(), 'nothing changed anywhere');
    }

    public function test_the_chat_loop_does_not_act_when_the_model_names_another_stores_ids(): void
    {
        $a = $this->account('A-dish');
        $b = $this->account(self::SECRET);
        $fake = new class extends AiService
        {
            public array $script = [];

            public int $idx = 0;

            public function __construct() {}

            public function json(array $messages, array $options = [], ?callable $validator = null, ?int $userId = null, ?int $storeId = null, string $feature = 'general'): array
            {
                return $this->script[$this->idx++] ?? ['reply' => 'done'];
            }
        };
        $fake->script = [
            ['tool' => 'create_order', 'args' => ['items' => [['id' => $b['item']->id, 'quantity' => 2]], 'type' => 'takeaway', 'customer_name' => 'Mallory']],
            ['reply' => 'sorry'],
        ];
        app()->instance(AiService::class, $fake);
        app()->instance(Ai::class, $fake);
        $conversation = AgentConversation::create(['store_id' => $a['store']->id, 'channel' => 'web', 'session_key' => 'k1', 'locale' => 'en']);
        $before = $this->snapshot();

        $reply = AgentChatService::handle($conversation, $a['store'], 'order two of the secret dish');

        $this->assertSame('sorry', $reply);
        $this->assertSame($before, $this->snapshot(), 'the model asked for another store\'s item: nothing was ordered anywhere');
    }

    public function test_voice_manifest_has_no_wallet_or_payment_tool(): void
    {
        $registry = AgentToolRegistry::withDefaults();
        $voice = array_keys($registry->tools(AgentTool::STOREFRONT, AgentTool::CHANNEL_VOICE));

        $this->assertContains('create_order', $voice, 'a caller can still place an unpaid order');
        $this->assertContains('search_menu', $voice);
        foreach ($voice as $name) {
            $this->assertDoesNotMatchRegularExpression('/wallet|credit|pay|charge|refund|card|balance|topup|top_up/i', $name, "{$name} must not be offered by voice");
        }
        foreach (StorefrontTools::VOICE_EXCLUDED as $name) {
            $this->assertNotNull($registry->find(AgentTool::STOREFRONT, $name, AgentTool::CHANNEL_CHAT), "{$name} still exists on chat");
            $this->assertNull($registry->find(AgentTool::STOREFRONT, $name, AgentTool::CHANNEL_VOICE));
        }
        $this->assertSame([], $registry->tools(AgentTool::OWNER, AgentTool::CHANNEL_VOICE), 'no owner tool on a call');
    }

    public function test_every_voice_tool_bound_to_one_store_refuses_another_stores_ids(): void
    {
        $a = $this->account('A-dish');
        $b = $this->account(self::SECRET);
        $before = $this->snapshot();
        $b['order']->refresh();
        $registry = AgentToolRegistry::withDefaults();
        // the trusted caller number is the OTHER store's customer: still nothing of store B comes back
        $context = ToolContext::forStore($a['store'], AgentTool::CHANNEL_VOICE, (string) $b['customer']->phone);
        $bToken = (string) ($b['order']->track_token ?? $b['order']->getAttribute('token') ?? '');
        $foreign = [
            'item_id' => $b['item']->id, 'items' => [['item_id' => $b['item']->id, 'quantity' => 1]],
            'cart_item_ids' => [$b['item']->id], 'track_token' => $bToken !== '' ? $bToken : 'nope', 'query' => self::SECRET,
            'phone' => $b['customer']->phone, 'type' => 'takeaway', 'customer_name' => 'Mallory', 'tag' => 'vegan',
            'coupon_code' => $b['coupon']->code, 'store_id' => $b['store']->id,
        ];
        $swept = 0;

        foreach ($registry->available(AgentTool::STOREFRONT, $context) as $name => $tool) {
            $schema = json_decode(json_encode($tool->inputSchema), true);
            $output = '';
            try {
                $output = json_encode($registry->call(AgentTool::STOREFRONT, $name, $this->argsFor($schema, $foreign), $context));
            } catch (ToolError) {
            }
            $this->assertStringNotContainsString(self::SECRET, (string) $output, "voice {$name}: leaked the other store's dish");
            $this->assertStringNotContainsString((string) $b['store']->slug, (string) $output, "voice {$name}: leaked the other store");
            $this->assertDoesNotMatchRegularExpression('/"order_id"\s*:\s*\d+|"ok"\s*:\s*true/', (string) $output, "voice {$name}: an order was accepted: {$output}");
            $swept++;
        }
        $this->assertGreaterThan(8, $swept, 'the sweep reached the voice tools');
        $this->assertSame($before, $this->snapshot(), 'nothing changed anywhere');
    }
}
