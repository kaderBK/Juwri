<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Mcp;

use App\Services\DevMcp\DevTools;
use Juwri\Modules\Agents\Mcp\Catalog\CatalogTools;
use Juwri\Modules\Agents\Mcp\Customer\CustomerTools;
use Juwri\Modules\Agents\Tools\AgentTool;
use Juwri\Modules\Agents\Tools\AgentToolRegistry;

/**
 * P1-16: the full (ungated, store-less, caller-less) tool lists of every Juwri MCP server, as MCP tool arrays — what
 * the MCP landing page counts and the annotation tests read (before per-token hiding).
 */
final class McpToolCatalog
{
    /** @return list<array<string, mixed>> */
    public function owner(): array
    {
        return self::listed(AgentTool::OWNER);
    }

    /** @return list<array<string, mixed>> */
    public function storefront(): array
    {
        return self::listed(AgentTool::STOREFRONT);
    }

    /** @return list<array<string, mixed>> */
    public function catalog(): array
    {
        return (new CatalogTools)->definitions();
    }

    /** @return list<array<string, mixed>> */
    public function customer(): array
    {
        return (new CustomerTools)->definitions();
    }

    /** @return list<array<string, mixed>> */
    public function dev(): array
    {
        return (new DevTools)->definitions();
    }

    /** @return list<array<string, mixed>> */
    private static function listed(string $audience): array
    {
        $out = [];
        foreach (app(AgentToolRegistry::class)->tools($audience, AgentTool::CHANNEL_MCP) as $tool) {
            $out[] = (new RegistryTool($tool))->toArray();
        }

        return $out;
    }
}
