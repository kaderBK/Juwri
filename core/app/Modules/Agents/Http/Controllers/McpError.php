<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Http\Controllers;

use Juwri\Modules\Agents\Tools\ToolError;

/**
 * A JSON-RPC error for the MCP endpoint. The numeric code maps to the JSON-RPC
 * `error.code` returned to the client. P1-16: a ToolError, so the shared tool
 * registry and every MCP server treat both alike.
 */
class McpError extends ToolError
{
}
