<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Mcp;

use Juwri\Core\Models\Store;
use Juwri\Modules\Agents\Tools\AgentTool;
use Laravel\Mcp\Server\Tool;

/**
 * P1-16: a shared AgentToolRegistry tool as a laravel/mcp Tool primitive. Listed as
 * `{name, description, inputSchema, annotations}` — the schema is the caller's store's own (create_order's
 * fulfillment modes) — exactly the shape the owner and storefront MCP servers have always advertised.
 */
final class RegistryTool extends Tool
{
    public function __construct(
        public readonly AgentTool $tool,
        private readonly ?Store $store = null,
    ) {
        $this->name = $tool->name;
        $this->description = $tool->description;
    }

    /** @return array{name: string, description: string, inputSchema: array<string, mixed>, annotations: array<string, bool>} */
    public function toArray(): array
    {
        return [
            'name' => $this->tool->name,
            'description' => $this->tool->description,
            'inputSchema' => $this->tool->schemaFor($this->store),
            'annotations' => $this->tool->annotations,
        ];
    }
}
