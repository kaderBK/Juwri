<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use Juwri\Modules\Agents\Tools\OwnerTools;
use Juwri\Modules\Agents\Mcp\McpToolCatalog;
use Juwri\Core\Http\Middleware\AbuseRateLimit;
use App\Http\Middleware\Installed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * M3-2 — every MCP tool across the five servers carries MCP annotations
 * (readOnlyHint, and destructive/idempotent hints on writes). Anthropic's
 * connector directory requires these; agents use them to decide what to
 * auto-run. The four Mcp*Test name contracts stay green (annotations are
 * additive; tool names are untouched — verified by those suites).
 */
class McpToolAnnotationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            Installed::class,
            ThrottleRequests::class,
            AbuseRateLimit::class,
        ]);
    }

    /**
     * A server's tool list: [class, method] (P1-16: every server's caller-less list comes from McpToolCatalog; the storefront tools
     * come from the shared agent tool registry via McpToolCatalog::storefront()).
     *
     * @param  class-string|array{0: class-string, 1: string}  $source
     * @return list<array<string,mixed>>
     */
    private function toolList(string|array $source): array
    {
        [$class, $method] = is_array($source) ? $source : [$source, 'toolList'];
        $instance = new ReflectionClass($class)->newInstanceWithoutConstructor();
        $m = new ReflectionMethod($class, $method);
        $m->setAccessible(true);

        return $m->invoke($instance);
    }

    /**
     * @param  class-string|array{0: class-string, 1: string}  $source
     * @return array<string,array<string,bool>> name => annotations
     */
    private function byName(string|array $source): array
    {
        $out = [];
        foreach ($this->toolList($source) as $t) {
            $out[$t['name']] = $t['annotations'] ?? null;
        }

        return $out;
    }

    public function test_every_public_tool_has_annotations(): void
    {
        foreach ([[McpToolCatalog::class, 'catalog'], [McpToolCatalog::class, 'customer'], [McpToolCatalog::class, 'dev'], [McpToolCatalog::class, 'storefront'], [McpToolCatalog::class, 'owner']] as $class) {
            foreach ($this->toolList($class) as $t) {
                $this->assertArrayHasKey('annotations', $t, "{$t['name']} missing annotations");
                $this->assertArrayHasKey('readOnlyHint', $t['annotations']);
            }
        }
    }

    public function test_catalog_and_customer_are_all_read_only(): void
    {
        foreach ([[McpToolCatalog::class, 'catalog'], [McpToolCatalog::class, 'customer']] as $class) {
            foreach ($this->byName($class) as $name => $ann) {
                $this->assertTrue($ann['readOnlyHint'], "{$name} should be read-only");
            }
        }
    }

    public function test_storefront_create_order_is_a_write_others_read(): void
    {
        $ann = $this->byName([McpToolCatalog::class, 'storefront']);
        $this->assertFalse($ann['create_order']['readOnlyHint']);
        $this->assertFalse($ann['create_order']['destructiveHint']);
        $this->assertTrue($ann['search_menu']['readOnlyHint']);
    }

    public function test_dev_submit_feedback_is_a_write_others_read(): void
    {
        $ann = $this->byName([McpToolCatalog::class, 'dev']);
        $this->assertFalse($ann['submit_feedback']['readOnlyHint']);
        $this->assertTrue($ann['search_docs']['readOnlyHint']);
        $this->assertTrue($ann['validate_theme']['readOnlyHint']);
    }

    public function test_admin_annotations_reads_writes_deletes_updates(): void
    {
        // P1-16: owner tool annotations are derived in the shared registry's OwnerTools.
        // Read (no write ability).
        $this->assertTrue(OwnerTools::annotationsFor('get_menu', null)['readOnlyHint']);
        // Destructive write.
        $del = OwnerTools::annotationsFor('delete_menu_item', 'menu:write');
        $this->assertFalse($del['readOnlyHint']);
        $this->assertTrue($del['destructiveHint']);
        // Idempotent write.
        $upd = OwnerTools::annotationsFor('update_coupon', 'orders:write');
        $this->assertTrue($upd['idempotentHint']);
        $this->assertFalse($upd['destructiveHint']);
        // Create write (neither destructive nor idempotent).
        $add = OwnerTools::annotationsFor('create_coupon', 'orders:write');
        $this->assertFalse($add['readOnlyHint']);
        $this->assertFalse($add['destructiveHint']);
        $this->assertFalse($add['idempotentHint']);
    }

    public function test_tools_list_over_the_wire_includes_annotations(): void
    {
        // End-to-end sanity on a public server: annotations reach the JSON-RPC
        // tools/list response, not just the internal array.
        $res = $this->postJson('/mcp/catalog', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list',
        ])->assertOk();

        $tools = $res->json('result.tools');
        $this->assertNotEmpty($tools);
        foreach ($tools as $t) {
            $this->assertArrayHasKey('annotations', $t);
        }
    }
}
