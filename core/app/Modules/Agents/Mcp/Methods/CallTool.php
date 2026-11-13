<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Mcp\Methods;

use Juwri\Modules\Agents\Mcp\JsonRpcMcpServer;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/**
 * P1-16: `tools/call` — through the shared registry, which refuses (ToolError) an unknown tool, a missing ability
 * or a missing feature before the handler runs.
 */
final class CallTool implements Method
{
    public function __construct(private readonly JsonRpcMcpServer $server) {}

    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        return JsonRpcResponse::result($request->id, $this->server->callTool(
            (string) $request->get('name', ''),
            (array) $request->get('arguments', []),
        ));
    }
}
