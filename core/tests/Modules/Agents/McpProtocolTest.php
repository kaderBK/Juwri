<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\StorefrontToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Customers\Models\CustomerToken;
use Juwri\Modules\Integrations\Models\ApiToken;
use Tests\Support\TestPlans;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * Protocol negotiation and Streamable HTTP transport security shared by all
 * five MCP servers (P1-16: McpRequestGuard + the laravel/mcp servers).
 */
class McpProtocolTest extends TestCase
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

    // --- auth helpers per server ------------------------------------------

    private function ownerAuth(): array
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $raw = Str::random(40);
        ApiToken::create(['user_id' => $user->id, 'name' => 't', 'token' => hash('sha256', $raw), 'abilities' => ['read']]);

        return ['/mcp', ['Authorization' => 'Bearer '.$raw]];
    }

    private function storefrontAuth(): array
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'storefront_api' => '1', 'agent_api' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $raw = Str::random(48);
        StorefrontToken::create(['store_id' => $r->id, 'token' => hash('sha256', $raw), 'scopes' => ['agent'], 'active' => true]);

        return ['/mcp/storefront', ['X-Storefront-Token' => $raw]];
    }

    private function customerAuth(): array
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $customer = Customer::create([
            'store_id' => $store->id,
            'name' => 'Customer',
            'phone' => UniquePhone::next('5'),
        ]);

        return ['/mcp/customer', ['Authorization' => 'Bearer '.CustomerToken::issue($customer)]];
    }

    /** Each server: [url, authHeaders]. Dev and catalog MCP are public. */
    private function servers(): array
    {
        return [
            'owner' => $this->ownerAuth(),
            'dev' => ['/mcp/dev', []],
            'storefront' => $this->storefrontAuth(),
            'catalog' => ['/mcp/catalog', []],
            'customer' => $this->customerAuth(),
        ];
    }

    // --- initialize negotiation -------------------------------------------

    public function test_initialize_echoes_supported_requested_version(): void
    {
        foreach ($this->servers() as $label => [$url, $headers]) {
            foreach (['2025-06-18', '2025-03-26', '2024-11-05'] as $version) {
                $this->postJson($url, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => $version]], $headers)
                    ->assertOk()
                    ->assertJsonPath('result.protocolVersion', $version, "$label should echo $version");
            }
        }
    }

    /** A JSON-RPC batch whose element is a scalar must not 500 (rpc() is array-typed). */
    public function test_scalar_batch_element_does_not_500(): void
    {
        foreach ($this->servers() as $label => [$url, $headers]) {
            $response = $this->postJson($url, [1], $headers);
            $this->assertLessThan(500, $response->status(), "$label: a scalar batch element must not 500 (got {$response->status()})");
        }
    }

    public function test_initialize_falls_back_on_unknown_or_absent_version(): void
    {
        foreach ($this->servers() as $label => [$url, $headers]) {
            // unknown requested version → default
            $this->postJson($url, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '1999-01-01']], $headers)
                ->assertOk()
                ->assertJsonPath('result.protocolVersion', '2025-06-18', "$label unknown → default");
            // absent → default
            $this->postJson($url, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []], $headers)
                ->assertOk()
                ->assertJsonPath('result.protocolVersion', '2025-06-18', "$label absent → default");
        }
    }

    // --- MCP-Protocol-Version header validation ---------------------------

    public function test_unknown_protocol_header_is_400(): void
    {
        foreach ($this->servers() as $label => [$url, $headers]) {
            $this->postJson($url, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $headers + ['MCP-Protocol-Version' => 'bogus-version'])
                ->assertStatus(400);
        }
    }

    public function test_known_or_absent_protocol_header_proceeds(): void
    {
        foreach ($this->servers() as $label => [$url, $headers]) {
            // known header → proceeds (200)
            $this->postJson($url, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $headers + ['MCP-Protocol-Version' => '2025-06-18'])
                ->assertOk();
            // absent header → proceeds (spec assumes 2025-03-26)
            $this->postJson($url, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $headers)
                ->assertOk();
        }
    }

    public function test_origin_must_be_same_origin_or_explicitly_allowed(): void
    {
        foreach ($this->servers() as $label => [$url, $headers]) {
            $body = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'];

            $this->postJson($url, $body, $headers + ['Origin' => 'https://localhost'])
                ->assertOk();
            $this->postJson($url, $body, $headers + ['Origin' => 'https://attacker.example'])
                ->assertStatus(403)
                ->assertJsonPath('error.code', -32600);
        }

        config(['api.mcp_allowed_origins' => ['https://trusted-agent.example']]);

        foreach ($this->servers() as $label => [$url, $headers]) {
            $this->postJson(
                $url,
                ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
                $headers + ['Origin' => 'https://trusted-agent.example']
            )->assertOk();
        }
    }

    public function test_notifications_are_accepted_with_202_for_single_and_batch_requests(): void
    {
        foreach ($this->servers() as $label => [$url, $headers]) {
            $notification = ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'];

            $this->postJson($url, $notification, $headers)
                ->assertStatus(202)
                ->assertContent('');
            $this->postJson($url, [$notification, $notification], $headers)
                ->assertStatus(202)
                ->assertContent('');
        }
    }

    public function test_post_accepts_recommended_accept_header_and_get_is_not_supported(): void
    {
        foreach ($this->servers() as $label => [$url, $headers]) {
            $this->postJson(
                $url,
                ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
                $headers + ['Accept' => 'application/json, text/event-stream']
            )->assertOk();

            $response = $this->get($url);

            if ($url === '/mcp') {
                // MCP-PAGE (D2-9): GET /mcp now renders the public landing page
                // (DEC-2). Only the four sub-paths keep the POST-only 405.
                $this->assertSame(200, $response->getStatusCode(), 'GET /mcp should render the landing page');
                $this->assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
            } else {
                $this->assertSame(405, $response->getStatusCode(), "$label GET should be explicitly rejected");
            }
        }
    }
}
