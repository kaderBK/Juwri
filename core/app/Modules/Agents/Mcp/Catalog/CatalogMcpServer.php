<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Mcp\Catalog;

use Juwri\Modules\Agents\Mcp\ToolSetServer;
use Laravel\Mcp\Server\Contracts\Transport;

/**
 * MCP-expansion P2 / P1-16: the platform-wide Catalog MCP server on laravel/mcp (POST /mcp/catalog, public,
 * read-only). Tools: {@see CatalogTools}.
 */
final class CatalogMcpServer extends ToolSetServer
{
    public function __construct(Transport $transport)
    {
        parent::__construct($transport, new CatalogTools);
    }

    protected function serverName(): string
    {
        return mcp_server_name('catalog-mcp');
    }
}
