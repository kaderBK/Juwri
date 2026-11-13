<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http\Controllers;

use App\Services\DevMcp\DevMcpServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Agents\Http\Controllers\McpServerController;
use Juwri\Modules\Agents\Mcp\McpHttpTransport;
use Juwri\Modules\Agents\Mcp\McpRequestGuard;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dev MCP (rev/dev-mcp) / P1-16: HTTP entry of the public developer docs/reference MCP server (POST /mcp/dev), served
 * by the laravel/mcp {@see DevMcpServer} (tools: App\Services\DevMcp\DevTools). Public and read-only — no auth, no
 * tenant data; rate limiting and input-size caps are the only guards. Same request-wide Streamable HTTP rules as
 * every MCP endpoint ({@see McpRequestGuard}).
 */
class DevMcpController extends Controller
{
    public function handle(Request $request, McpRequestGuard $guard): Response|JsonResponse
    {
        if ($bad = $guard->check($request)) {
            return $bad;
        }
        $transport = new McpHttpTransport($request);

        return McpServerController::serve(new DevMcpServer($transport), $transport);
    }
}
