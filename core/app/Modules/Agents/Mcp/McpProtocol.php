<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Mcp;

/** P1-16: the MCP protocol versions every Juwri MCP server negotiates (spec 2025-06-18 rules). */
final class McpProtocol
{
    /** Versions we understand, newest last. */
    public const SUPPORTED = ['2024-11-05', '2025-03-26', '2025-06-18'];

    /** Returned when the client asks for a version we do not support. */
    public const DEFAULT = '2025-06-18';
}
