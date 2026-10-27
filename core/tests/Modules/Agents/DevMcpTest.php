<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use App\Http\Middleware\Installed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Dev MCP (rev/dev-mcp P1): the public developer-docs MCP endpoint. No auth by
 * design — everything it serves is already public. These tests pin the JSON-RPC
 * envelope, the learn_platform primer, and the throttle.
 */
class DevMcpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(Installed::class);
    }

    private function rpc(string $method, array $params = [], int $id = 1)
    {
        return $this->postJson('/mcp/dev', ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]);
    }

    public function test_no_token_needed_and_initialize_returns_server_info(): void
    {
        $this->rpc('initialize')
            ->assertOk()
            ->assertJsonPath('result.serverInfo.name', 'juwri-dev-mcp')
            ->assertJsonPath('result.protocolVersion', '2025-06-18');
    }

    public function test_unknown_method_is_a_json_rpc_error(): void
    {
        $this->rpc('bogus/method')
            ->assertOk()
            ->assertJsonPath('error.code', -32601);
    }

    public function test_unknown_tool_is_a_json_rpc_error(): void
    {
        $this->rpc('tools/call', ['name' => 'no_such_tool'])
            ->assertOk()
            ->assertJsonPath('error.code', -32602);
    }

    public function test_tools_list_includes_learn_platform_with_llm_grade_description(): void
    {
        $res = $this->rpc('tools/list')->assertOk()->json('result.tools');

        $names = array_column($res, 'name');
        $this->assertContains('learn_platform', $names);

        $learn = $res[array_search('learn_platform', $names, true)];
        // Kickoff quality bar: description must route the agent (call-first + next tools).
        $this->assertStringContainsString('FIRST', $learn['description']);
        $this->assertStringContainsString('search_docs', $learn['description']);
    }

    public function test_learn_platform_returns_overview_primer_by_default(): void
    {
        $text = $this->rpc('tools/call', ['name' => 'learn_platform'])
            ->assertOk()->json('result.content.0.text');

        $this->assertStringContainsString('Platform overview for AI agents', $text);
        $this->assertStringContainsString('/api/v1/storefront/menu', $text);
    }

    public function test_learn_platform_topic_themes_returns_theme_primer(): void
    {
        $text = $this->rpc('tools/call', ['name' => 'learn_platform', 'arguments' => ['topic' => 'themes']])
            ->assertOk()->json('result.content.0.text');

        $this->assertStringContainsString('Building Liquid themes', $text);
        $this->assertStringContainsString('validate_theme', $text);
    }

    public function test_learn_platform_topic_apps_returns_app_primer(): void
    {
        $text = $this->rpc('tools/call', ['name' => 'learn_platform', 'arguments' => ['topic' => 'apps']])
            ->assertOk()->json('result.content.0.text');

        $this->assertStringContainsString('Building marketplace apps', $text);
        $this->assertStringContainsString('/api/app/usage', $text);
        $this->assertStringContainsString('/developers/apps', $text);
    }

    public function test_learn_platform_rejects_unknown_topic_listing_valid_ones(): void
    {
        $this->rpc('tools/call', ['name' => 'learn_platform', 'arguments' => ['topic' => 'nope']])
            ->assertOk()
            ->assertJsonPath('error.code', -32602)
            ->assertJsonFragment(['message' => 'Unknown topic: nope. Valid: overview, themes, apps, storefront-api, customer-api.']);
    }

    public function test_notifications_get_no_response_body(): void
    {
        $this->postJson('/mcp/dev', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])
            ->assertStatus(202)
            ->assertContent('');
    }

    public function test_endpoint_is_rate_limited(): void
    {
        RateLimiter::clear(sha1('60,1'.request()->ip()));

        $last = null;
        for ($i = 0; $i < 61; $i++) {
            $last = $this->rpc('ping', [], $i + 1);
        }

        $last->assertStatus(429);
    }
}
