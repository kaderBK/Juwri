<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Agents\Http\Middleware\McpOwnerAuth;
use Juwri\Modules\Agents\Mcp\Catalog\CatalogMcpServer;
use Juwri\Modules\Agents\Mcp\Customer\CustomerMcpServer;
use Juwri\Modules\Agents\Mcp\JsonRpcMcpServer;
use Juwri\Modules\Agents\Mcp\McpHttpTransport;
use Juwri\Modules\Agents\Mcp\McpRequestGuard;
use Juwri\Modules\Agents\Mcp\OwnerMcpServer;
use Juwri\Modules\Agents\Mcp\StorefrontMcpServer;
use Juwri\Modules\Agents\Tools\AgentTool;
use Juwri\Modules\Agents\Tools\AgentToolRegistry;
use Juwri\Modules\Agents\Tools\ToolContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * P1-16: HTTP entry of the laravel/mcp servers — owner POST /mcp, storefront POST /mcp/storefront, catalog POST
 * /mcp/catalog, customer POST /mcp/customer (the dev server's entry is App\Http\Controllers\DevMcpController).
 * Request-wide Streamable HTTP rules first ({@see McpRequestGuard}: origin allowlist, MCP-Protocol-Version header),
 * then the caller — resolved by the route's auth middleware, never from the JSON-RPC body — is handed to the server.
 */
class McpServerController extends Controller
{
    public function __construct(private readonly McpRequestGuard $guard) {}

    public function owner(Request $request, AgentToolRegistry $registry): Response|JsonResponse
    {
        if ($bad = $this->guard->check($request)) {
            return $bad;
        }

        $caller = ToolContext::forOwner(
            (int) $request->attributes->get('_api_user_id'),
            AgentTool::CHANNEL_MCP,
            McpOwnerAuth::abilities($request),
        );
        $transport = new McpHttpTransport($request);

        return self::serve(new OwnerMcpServer($transport, $caller, $registry), $transport);
    }

    public function storefront(Request $request, AgentToolRegistry $registry): Response|JsonResponse
    {
        if ($bad = $this->guard->check($request)) {
            return $bad;
        }

        $store = app('api.storefront_post'); // bound by StorefrontTokenAuth:agent
        $caller = ToolContext::forStore($store, AgentTool::CHANNEL_MCP);
        $sessionKey = substr(hash('sha256', (string) $request->header('X-Storefront-Token', 'anon')), 0, 40);
        $transport = new McpHttpTransport($request);

        return self::serve(new StorefrontMcpServer($transport, $caller, $registry, $sessionKey), $transport);
    }

    /** MCP-expansion P2: public, read-only, platform-wide discovery (no auth; rate limits + result caps). */
    public function catalog(Request $request): Response|JsonResponse
    {
        if ($bad = $this->guard->check($request)) {
            return $bad;
        }
        $transport = new McpHttpTransport($request);

        return self::serve(new CatalogMcpServer($transport), $transport);
    }

    /** MCP-expansion P3: the token's customer (CustomerTokenAuth binds api.customer) reads their own data. */
    public function customer(Request $request): Response|JsonResponse
    {
        if ($bad = $this->guard->check($request)) {
            return $bad;
        }
        $transport = new McpHttpTransport($request);

        return self::serve(new CustomerMcpServer($transport, app('api.customer')), $transport);
    }

    public static function serve(JsonRpcMcpServer $server, McpHttpTransport $transport): Response
    {
        $server->start();

        return $transport->run();
    }
}
