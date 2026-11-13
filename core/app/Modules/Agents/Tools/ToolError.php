<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Tools;

use RuntimeException;

/**
 * P1-16: a tool refusal the caller may see. The code is the JSON-RPC `error.code` an MCP server returns; chat
 * channels show the message. Any other exception from a tool is internal and never reaches the client verbatim.
 */
class ToolError extends RuntimeException
{
    /** Unknown tool / invalid arguments. */
    public const INVALID_PARAMS = -32602;

    /** The caller's token lacks the tool's ability. */
    public const ABILITY_MISSING = -32001;

    /** The account's plan / the platform does not grant this tool (HTTP 403 on a single MCP message). */
    public const NOT_ENTITLED = -32003;

    /** The store / row is not found or not the caller's. */
    public const NOT_FOUND = -32004;

    public function __construct(int $code, string $message)
    {
        parent::__construct($message, $code);
    }
}
