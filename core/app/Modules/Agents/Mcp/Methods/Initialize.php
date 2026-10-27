<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Mcp\Methods;

use Juwri\Modules\Agents\Mcp\JsonRpcMcpServer;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;

/** P1-16: `initialize` — echo a supported protocolVersion (else the default), tools capability, serverInfo. */
final class Initialize implements Method
{
    public function __construct(private readonly JsonRpcMcpServer $server) {}

    public function handle(JsonRpcRequest $request, ServerContext $context): JsonRpcResponse
    {
        return JsonRpcResponse::result($request->id, [
            'protocolVersion' => $this->server->negotiateProtocol($request->params),
            'capabilities' => $context->serverCapabilities,
            'serverInfo' => ['name' => $context->implementation->name, 'version' => $context->implementation->version],
        ]);
    }
}
