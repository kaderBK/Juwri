<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Services\Entitlements;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Feature;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\EntitlementWriter;
use Juwri\Modules\Agents\Tools\ToolError;
use Juwri\Modules\Integrations\Models\ApiToken;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * F6-1 (feature-platform gap fix): `crm_suite` gates the DATA APIs, not just
 * the dashboard.
 *
 * The audit's bypass: the web CRM sits behind `feature:crm_suite`
 * (routes/web.php, the `crm.*` group), but GET /api/v1/stores/{id}/customers
 * and the MCP `list_customers` / `get_customer` tools were guarded by token
 * ABILITIES alone. An owner with no CRM entitlement minted a `customers:read`
 * token and read every guest profile — name, phone, email, lifetime spend — for
 * free, and a superadmin kill-switch flip did not stop them either.
 *
 * Customer PII is the asset, so the contract frozen here is strict about the
 * SHAPE of the refusal as well as its status: no partial list, no leaked row
 * count, no data at all.
 *
 *   unentitled owner  → JSON 403 / JSON-RPC error, zero customer rows
 *   entitled owner    → 200 with data
 *   killed feature    → refused even for an entitled owner (kill veto wins)
 *
 * The MCP layer additionally HIDES the tools from tools/list in the refused
 * states — an agent should not be told about a capability it cannot use.
 */
class FeaturePlatformFix6CrmGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);

        // Baseline for every case: the key is plan_gated (PremiumBundleSeeder
        // does this in prod) and the platform kill-switch is ON.
        Feature::where('key', 'crm_suite')->update(['plan_gated' => true]);
        FeatureRegistry::clearCache(); // mass update fires no model event
        $this->killSwitch(true);
        $this->forgetGatedMemo();
    }

    // ------------------------------------------------------------- fixtures

    private function killSwitch(bool $on): void
    {
        config(['settings.admin_allow_crm_suite' => $on ? 1 : 0]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();
    }

    /** The plan_gated set is memoised per request; a test spans several. */
    private function forgetGatedMemo(): void
    {
        app()->forgetInstance('features.plan_gated');
        FeatureAccess::flush();
    }

    /** @return array{0:User,1:Store} an owner with one restaurant, on a bare plan. */
    private function owner(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(6), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999], 'status' => 1,
        ]);
        $user = User::factory()->create([
            'group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(),
        ]);
        $title = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $user->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$user, $store];
    }

    private function token(User $user): string
    {
        $raw = Str::random(40);
        ApiToken::create([
            'user_id' => $user->id, 'name' => 't',
            'token' => hash('sha256', $raw), 'abilities' => ['read', 'write'],
        ]);

        return $raw;
    }

    private function entitle(User $user): void
    {
        EntitlementWriter::grant($user, 'crm_suite', 'admin', null, '1', null, 'test-fixture');
        FeatureAccess::flush();
    }

    /** One guest whose PII must never appear in a refused response. */
    private function customer(Store $store): Customer
    {
        return Customer::create([
            'store_id' => $store->id,
            'name' => 'Leaky McPii',
            'phone' => '+15550000001',
            'email' => 'leaky-'.Str::lower(Str::random(6)).'@guest.test',
        ]);
    }

    private function rpc(string $token, string $method, array $params = [])
    {
        return $this->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params,
        ], ['Authorization' => 'Bearer '.$token]);
    }

    private function callTool(string $token, string $tool, array $args)
    {
        return $this->rpc($token, 'tools/call', ['name' => $tool, 'arguments' => $args]);
    }

    // ================================================================== REST

    public function test_rest_customers_endpoint_refuses_an_unentitled_token_with_json_403(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        [$owner, $store] = $this->owner();
        $guest = $this->customer($store);
        $token = $this->token($owner);

        $this->assertFalse(
            Entitlements::allows($owner->fresh(), 'crm_suite'),
            'the fixture owner must start with no crm_suite entitlement'
        );

        $this->forgetGatedMemo();
        $res = $this->getJson('/api/v1/stores/'.$store->id.'/customers', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $res->assertStatus(403);
        // Machine-readable: a partner can tell "not entitled" from "bad token".
        $this->assertSame('crm_suite', $res->json('feature'));
        // And not one byte of PII came back.
        $res->assertDontSee($guest->phone)->assertDontSee($guest->name);
    }

    public function test_rest_customers_endpoint_serves_an_entitled_token(): void
    {
        [$owner, $store] = $this->owner();
        $guest = $this->customer($store);
        $this->entitle($owner);
        $token = $this->token($owner);

        $this->forgetGatedMemo();
        $this->getJson('/api/v1/stores/'.$store->id.'/customers', [
            'Authorization' => 'Bearer '.$token,
        ])->assertOk()->assertSee($guest->name);
    }

    public function test_rest_customers_endpoint_obeys_the_kill_switch_even_when_entitled(): void
    {
        [$owner, $store] = $this->owner();
        $guest = $this->customer($store);
        $this->entitle($owner);
        $token = $this->token($owner);

        $this->killSwitch(false);
        $this->forgetGatedMemo();

        $this->getJson('/api/v1/stores/'.$store->id.'/customers', [
            'Authorization' => 'Bearer '.$token,
        ])->assertStatus(403)->assertDontSee($guest->phone);
    }

    public function test_the_rest_gate_is_owner_scoped_not_session_scoped(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        // An entitled owner logged into the dashboard must not lend their
        // entitlement to a DIFFERENT owner's API token.
        [$entitledOwner] = $this->owner();
        $this->entitle($entitledOwner);

        [$poorOwner, $poorPost] = $this->owner();
        $token = $this->token($poorOwner);

        $this->forgetGatedMemo();
        $this->actingAs($entitledOwner)
            ->getJson('/api/v1/stores/'.$poorPost->id.'/customers', [
                'Authorization' => 'Bearer '.$token,
            ])->assertStatus(403);
    }

    // =================================================================== MCP

    public function test_mcp_customer_tools_refuse_an_unentitled_token(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        [$owner, $store] = $this->owner();
        $guest = $this->customer($store);
        $token = $this->token($owner);

        foreach ([
            'list_customers' => ['store_id' => $store->id],
            'get_customer' => ['store_id' => $store->id, 'customer_id' => $guest->id],
        ] as $tool => $args) {
            $this->forgetGatedMemo();
            $res = $this->callTool($token, $tool, $args);

            $res->assertStatus(403, $tool.' must refuse an unentitled token');
            $this->assertSame(
                ToolError::NOT_ENTITLED,
                $res->json('error.code'),
                $tool.' must answer with the not-entitled JSON-RPC code'
            );
            $this->assertStringContainsString('crm_suite', (string) $res->json('error.message'));
            // No result envelope at all — a partial list is the leak.
            $this->assertNull($res->json('result'), $tool.' must not return a result');
            $res->assertDontSee($guest->phone)->assertDontSee('Leaky McPii');
        }
    }

    public function test_mcp_customer_tools_serve_an_entitled_token(): void
    {
        [$owner, $store] = $this->owner();
        $guest = $this->customer($store);
        $this->entitle($owner);
        $token = $this->token($owner);

        $this->forgetGatedMemo();
        $this->callTool($token, 'list_customers', ['store_id' => $store->id])
            ->assertOk()->assertSee('Leaky McPii');

        $this->forgetGatedMemo();
        $this->callTool($token, 'get_customer', [
            'store_id' => $store->id, 'customer_id' => $guest->id,
        ])->assertOk()->assertSee('Leaky McPii');
    }

    public function test_mcp_kill_switch_vetoes_the_customer_tools_even_when_entitled(): void
    {
        [$owner, $store] = $this->owner();
        $guest = $this->customer($store);
        $this->entitle($owner);
        $token = $this->token($owner);

        $this->killSwitch(false);

        foreach ([
            'list_customers' => ['store_id' => $store->id],
            'get_customer' => ['store_id' => $store->id, 'customer_id' => $guest->id],
        ] as $tool => $args) {
            $this->forgetGatedMemo();
            $this->callTool($token, $tool, $args)
                ->assertStatus(403, $tool.' must stay closed while crm_suite is killed')
                ->assertDontSee($guest->phone);
        }
    }

    public function test_mcp_tools_list_hides_the_customer_tools_unless_entitled(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        [$owner, $store] = $this->owner();
        $token = $this->token($owner);

        $listedTools = function () use ($token): array {
            $this->forgetGatedMemo();

            return array_column($this->rpc($token, 'tools/list')->assertOk()->json('result.tools') ?? [], 'name');
        };

        // (a) unentitled → hidden
        $listed = $listedTools();
        $this->assertNotContains('list_customers', $listed);
        $this->assertNotContains('get_customer', $listed);
        // A neighbouring ungated tool is still advertised — we hid the right ones.
        $this->assertContains('list_restaurants', $listed);

        // (b) entitled → advertised
        $this->entitle($owner);
        $listed = $listedTools();
        $this->assertContains('list_customers', $listed);
        $this->assertContains('get_customer', $listed);

        // (c) killed → hidden again, despite the entitlement
        $this->killSwitch(false);
        $listed = $listedTools();
        $this->assertNotContains('list_customers', $listed);
        $this->assertNotContains('get_customer', $listed);
    }

    // ------------------------------------------------------------ guardrails

    public function test_an_ungated_crm_suite_keeps_kill_only_behavior(): void
    {
        // Regression guard: with plan_gated off, both transports behave exactly
        // as before the fix (abilities + kill only).
        Feature::where('key', 'crm_suite')->update(['plan_gated' => false]);
        FeatureRegistry::clearCache(); // mass update fires no model event
        $this->forgetGatedMemo();

        [$owner, $store] = $this->owner();
        $this->customer($store);
        $token = $this->token($owner);

        $this->getJson('/api/v1/stores/'.$store->id.'/customers', [
            'Authorization' => 'Bearer '.$token,
        ])->assertOk();
        $this->callTool($token, 'list_customers', ['store_id' => $store->id])->assertOk();

        $this->killSwitch(false);
        $this->forgetGatedMemo();

        $this->getJson('/api/v1/stores/'.$store->id.'/customers', [
            'Authorization' => 'Bearer '.$token,
        ])->assertStatus(403);
        $this->callTool($token, 'list_customers', ['store_id' => $store->id])->assertStatus(403);
    }

    public function test_other_mcp_tools_are_unaffected_by_the_crm_gate(): void
    {
        // The gate is per-tool, not per-endpoint: an unentitled token still
        // drives every tool that declares no `feature`.
        [$owner, $store] = $this->owner();
        $token = $this->token($owner);

        $this->forgetGatedMemo();
        $this->callTool($token, 'list_restaurants', [])->assertOk();

        $this->forgetGatedMemo();
        $this->callTool($token, 'get_menu', ['store_id' => $store->id])->assertOk();
    }
}
