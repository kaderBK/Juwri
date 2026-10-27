<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Mcp;

use Juwri\Modules\Agents\Models\AgentConversation;
use Juwri\Modules\Agents\Models\AgentMessage;
use Juwri\Modules\Agents\Tools\AgentTool;
use Juwri\Modules\Agents\Tools\AgentToolRegistry;
use Juwri\Modules\Agents\Tools\ToolContext;
use Laravel\Mcp\Server\Contracts\Transport;

/**
 * Agents P3 / P1-16: the customer/agent-facing storefront MCP server (POST /mcp/storefront). Auth'd by a storefront
 * token with the `agent` scope; the store comes from the token (StorefrontTokenAuth), never from the client. Tools
 * are the shared StorefrontTools — the same ones the chat widget, WhatsApp, SMS and voice use — plus the MCP-only
 * hotel_availability. MCP callers are not phone-verified, so customer-history tools answer "not found".
 */
final class StorefrontMcpServer extends JuwriMcpServer
{
    public function __construct(
        Transport $transport,
        ToolContext $caller,
        AgentToolRegistry $registry,
        private readonly string $sessionKey,
    ) {
        parent::__construct($transport, $caller, $registry);
    }

    public function audience(): string
    {
        return AgentTool::STOREFRONT;
    }

    protected function serverName(): string
    {
        return mcp_server_name('storefront-mcp');
    }

    public function callTool(string $name, array $args): array
    {
        $store = $this->caller->store;

        // Audit trail: every call is logged under the token's mcp conversation.
        $conversation = AgentConversation::firstOrCreate(
            ['store_id' => $store?->id, 'channel' => 'mcp', 'session_key' => $this->sessionKey],
            []
        );
        AgentMessage::create(['conversation_id' => $conversation->id, 'role' => 'tool', 'content' => $name, 'tool_calls' => ['tool' => $name, 'args' => $args]]);

        $tool = $this->registry->find(AgentTool::STOREFRONT, $name, AgentTool::CHANNEL_MCP);
        if ($tool === null) {
            return ['content' => [['type' => 'text', 'text' => 'Unknown tool.']], 'isError' => true];
        }

        return ['content' => [['type' => 'text', 'text' => json_encode($tool->run($this->caller, $args))]]];
    }
}
