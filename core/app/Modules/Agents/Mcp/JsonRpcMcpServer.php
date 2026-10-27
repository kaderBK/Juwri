<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Mcp;

use Juwri\Modules\Agents\Mcp\Methods\CallTool;
use Juwri\Modules\Agents\Mcp\Methods\Initialize;
use Juwri\Modules\Agents\Mcp\Methods\ListTools;
use Juwri\Modules\Agents\Mcp\Methods\Ping;
use Juwri\Modules\Agents\Tools\ToolError;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Contracts\Method;
use Laravel\Mcp\Server\Contracts\Transport;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use Throwable;

/**
 * P1-16: base of every Juwri MCP server on laravel/mcp (owner, storefront, catalog, customer, dev). The methods are
 * laravel/mcp Method classes, the tools laravel/mcp Tool primitives; this class keeps the wire contract clients
 * already use (pinned by McpCharacterisationTest and the per-server tests):
 *
 *  - JSON-RPC batches are answered as a list; notifications get no reply (HTTP 202);
 *  - a refusal is `{error:{code,message}}` with the ToolError's code (McpError is one);
 *  - results carry no `resultType` / `_meta` (laravel/mcp adds those for its 2026 protocol; the clients here
 *    negotiate 2024-11-05 .. 2025-06-18, {@see McpProtocol});
 *  - any other exception is reported and answered -32603 "Internal error" — its message never reaches the client.
 */
abstract class JsonRpcMcpServer extends Server
{
    protected string $version = '1.0.0';

    /** @var array<string, array<string, bool>|\stdClass|string> */
    protected array $capabilities = [
        self::CAPABILITY_TOOLS => ['listChanged' => false],
    ];

    /** @var array<string, class-string<Method>> */
    protected array $methods = [
        'initialize' => Initialize::class,
        'ping' => Ping::class,
        'tools/list' => ListTools::class,
        'tools/call' => CallTool::class,
    ];

    public function __construct(Transport $transport)
    {
        parent::__construct($transport);
        $this->name = $this->serverName();
        $this->supportedProtocolVersion = McpProtocol::SUPPORTED;
    }

    /** serverInfo.name (brand-aware). */
    abstract protected function serverName(): string;

    /**
     * Run a tool and shape the MCP tools/call result.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     *
     * @throws ToolError
     */
    abstract public function callTool(string $name, array $args): array;

    /** @param  array<string, mixed>  $params */
    public function negotiateProtocol(array $params): string
    {
        $requested = $params['protocolVersion'] ?? null;

        return is_string($requested) && in_array($requested, McpProtocol::SUPPORTED, true)
            ? $requested
            : McpProtocol::DEFAULT;
    }

    public function handle(string $rawMessage): void
    {
        // Same decoding as Request::json(): invalid JSON is an empty message.
        $body = (array) json_decode($rawMessage !== '' ? $rawMessage : '[]', true);

        if (array_is_list($body) && $body !== []) {
            $replies = array_values(array_filter(
                array_map(fn ($message) => $this->message((array) $message), $body),
                static fn ($reply) => $reply !== null,
            ));
            if ($replies !== []) {
                $this->transport->send((string) json_encode($replies));
            }

            return;
        }

        $reply = $this->message($body);
        if ($reply !== null) {
            $this->transport->send((string) json_encode($reply));
        }
    }

    /**
     * One JSON-RPC message; null for a notification.
     *
     * @param  array<mixed>  $message
     * @return array<string, mixed>|null
     */
    protected function message(array $message): ?array
    {
        $id = $message['id'] ?? null;
        $method = $message['method'] ?? '';
        $params = $message['params'] ?? [];

        if ($id === null && is_string($method) && str_starts_with($method, 'notifications/')) {
            return null;
        }

        try {
            $method = is_string($method) ? $method : '';
            $class = $this->methods[$method] ?? null;
            if ($class === null) {
                throw new ToolError(-32601, "Method not found: {$method}");
            }
            $request = new JsonRpcRequest(is_int($id) || is_string($id) ? $id : 0, $method, (array) $params);
            $handler = new $class($this);
            $response = $handler->handle($request, $this->createContext());
            $result = $response instanceof JsonRpcResponse ? $response->content['result'] : null;
        } catch (ToolError $e) {
            return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $e->getCode(), 'message' => $e->getMessage()]];
        } catch (Throwable $e) {
            report($e);

            return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32603, 'message' => 'Internal error']];
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }
}
