<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services\DevMcp;

use Juwri\Modules\Agents\Mcp\ToolSetServer;
use Laravel\Mcp\Server\Contracts\Transport;

/**
 * Dev MCP / P1-16: the public developer docs/reference MCP server on laravel/mcp (POST /mcp/dev). Tools:
 * {@see DevTools}.
 */
final class DevMcpServer extends ToolSetServer
{
    public function __construct(Transport $transport)
    {
        parent::__construct($transport, new DevTools);
    }

    protected function serverName(): string
    {
        return mcp_server_name('dev-mcp');
    }
}
