<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Mcp\Customer;

use App\Models\Customer;
use Juwri\Modules\Agents\Mcp\ToolSetServer;
use Laravel\Mcp\Server\Contracts\Transport;

/**
 * MCP-expansion P3 / P1-16: the Customer Account MCP server on laravel/mcp (POST /mcp/customer). The customer is the
 * one the route's CustomerTokenAuth resolved from the token — never a JSON-RPC argument. Tools: {@see CustomerTools}.
 */
final class CustomerMcpServer extends ToolSetServer
{
    public function __construct(Transport $transport, Customer $customer)
    {
        parent::__construct($transport, new CustomerTools($customer));
    }

    protected function serverName(): string
    {
        return mcp_server_name('customer-mcp');
    }
}
