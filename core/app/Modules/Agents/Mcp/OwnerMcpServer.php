<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Mcp;

use Juwri\Modules\Agents\Http\Middleware\McpOwnerAuth;
use Juwri\Modules\Agents\Tools\AgentTool;

/**
 * #102 / P1-16: the owner MCP server (POST /mcp). Lets an MCP client operate the platform with the authenticated
 * account's owner tools (OwnerTools on the shared registry): read tools for any valid credential, mutating tools
 * need the write ability and are audited. Every tool is scoped to the caller's own stores — a request can never
 * touch another tenant's data. Auth: the account's ApiToken or a Passport (OAuth 2.1) token with `mcp:use`
 * ({@see McpOwnerAuth}).
 */
final class OwnerMcpServer extends JuwriMcpServer
{
    public function audience(): string
    {
        return AgentTool::OWNER;
    }

    protected function serverName(): string
    {
        return mcp_server_name('mcp');
    }

    public function callTool(string $name, array $args): array
    {
        $data = $this->registry->call(AgentTool::OWNER, $name, $args, $this->caller);

        return ['content' => [['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]]];
    }
}
