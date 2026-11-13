<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Mcp;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * P1-16: the request-wide Streamable HTTP rules of every Juwri MCP endpoint, checked before the JSON-RPC body is
 * handed to a laravel/mcp server (was the HandlesMcpProtocol controller trait). Per the MCP spec (2025-06-18):
 *
 *  - Non-initialize requests carry an `MCP-Protocol-Version` HTTP header. A known value or an absent header proceeds
 *    (absent => the spec's assumed `2025-03-26`); an unknown value is a hard HTTP 400.
 *  - Browser requests must be same-origin or match the explicit configured allowlist (`api.mcp_allowed_origins`);
 *    non-browser clients normally omit Origin and remain supported (DNS-rebinding guard), else HTTP 403.
 *
 * Protocol negotiation of `initialize` is the server's ({@see JsonRpcMcpServer::negotiateProtocol()}); one JSON
 * response per POST (valid Streamable HTTP; no SSE).
 */
final class McpRequestGuard
{
    /**
     * Validate request-wide Streamable HTTP requirements before parsing or
     * dispatching JSON-RPC. Returns an HTTP error response, or null to proceed.
     */
    public function check(Request $request): ?JsonResponse
    {
        if ($bad = $this->guardOrigin($request)) {
            return $bad;
        }

        return $this->guardProtocolHeader($request);
    }

    /**
     * Validate the MCP-Protocol-Version header. Returns a 400 JSON-RPC error
     * response when the header is present but unknown; null to proceed (a known
     * value or an absent header — absent is the spec's assumed 2025-03-26).
     */
    private function guardProtocolHeader(Request $request): ?JsonResponse
    {
        $version = $request->header('MCP-Protocol-Version');

        if ($version !== null && $version !== '' && ! in_array($version, McpProtocol::SUPPORTED, true)) {
            return response()->json([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => [
                    'code' => -32600,
                    'message' => "Unsupported MCP-Protocol-Version: {$version}. Supported: "
                        .implode(', ', McpProtocol::SUPPORTED),
                ],
            ], 400);
        }

        return null;
    }

    /**
     * Reject cross-origin browser requests unless their exact origin is
     * explicitly configured. This prevents DNS-rebinding attacks while keeping
     * CLI/editor clients, which do not send Origin, fully compatible.
     */
    private function guardOrigin(Request $request): ?JsonResponse
    {
        $origin = trim((string) $request->header('Origin', ''));

        if ($origin === '') {
            return null;
        }

        $normalized = $this->normalizeOrigin($origin);
        $configured = config('api.mcp_allowed_origins', []);
        $configured = is_array($configured) ? $configured : [$configured];

        $allowed = array_filter(array_map(
            fn ($candidate) => $this->normalizeOrigin((string) $candidate),
            array_merge([$request->getSchemeAndHttpHost()], $configured)
        ));

        if ($normalized !== null && in_array($normalized, $allowed, true)) {
            return null;
        }

        return response()->json([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => [
                'code' => -32600,
                'message' => 'Origin is not allowed for this MCP endpoint.',
            ],
        ], 403);
    }

    /** Return a canonical scheme/host/effective-port tuple for exact matching. */
    private function normalizeOrigin(string $origin): ?string
    {
        $parts = parse_url(trim($origin));

        if (! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
            || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])
            || (isset($parts['path']) && ! in_array($parts['path'], ['', '/'], true))) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return $scheme.'://'.strtolower($parts['host']).':'.$port;
    }
}
