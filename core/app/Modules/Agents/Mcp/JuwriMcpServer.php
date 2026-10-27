<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Mcp;

use Juwri\Modules\Agents\Tools\AgentToolRegistry;
use Juwri\Modules\Agents\Tools\ToolContext;
use Laravel\Mcp\Server\Contracts\Transport;

/**
 * P1-16: base of the registry-backed Juwri MCP servers (owner, storefront). The tools are the shared
 * AgentToolRegistry's, as {@see RegistryTool} primitives for the authenticated caller; envelope and methods are
 * {@see JsonRpcMcpServer}'s.
 */
abstract class JuwriMcpServer extends JsonRpcMcpServer
{
    public function __construct(
        Transport $transport,
        public readonly ToolContext $caller,
        protected readonly AgentToolRegistry $registry,
    ) {
        parent::__construct($transport);
        foreach ($registry->available($this->audience(), $caller) as $tool) {
            $this->tools[] = new RegistryTool($tool, $caller->store);
        }
    }

    /** The registry audience this server serves (AgentTool::OWNER / STOREFRONT). */
    abstract public function audience(): string;
}
