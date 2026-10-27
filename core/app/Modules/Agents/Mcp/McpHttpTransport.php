<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Mcp;

use Juwri\Modules\Agents\Tools\ToolError;
use Laravel\Mcp\Server\Transport\HttpTransport;

/**
 * P1-16: laravel/mcp's HTTP transport with the status codes Juwri clients rely on: 202 when nothing is answered
 * (notifications only), 403 when a single message is refused as not entitled (FIX-6 F6-1: an authorization failure,
 * not a tool result), 200 for everything else — including every other JSON-RPC error and every batch (a batch can
 * mix refused and served messages).
 */
final class McpHttpTransport extends HttpTransport
{
    protected function statusCode(): int
    {
        if ($this->reply === null) {
            return 202;
        }

        $reply = json_decode($this->reply, true);

        return is_array($reply) && ! array_is_list($reply) && ($reply['error']['code'] ?? null) === ToolError::NOT_ENTITLED
            ? 403
            : 200;
    }
}
