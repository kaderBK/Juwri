<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Mcp;

use Juwri\Modules\Agents\Tools\ToolError;

/**
 * P1-16: the tools of one {@see ToolSetServer}: their definitions (listed as-is by tools/list) and the runner that
 * answers tools/call. definitions() is pure (no request, DB or caller) — the landing page and the annotation tests
 * read it without a caller.
 */
interface McpToolSet
{
    /** @return list<array{name: string, description: string, inputSchema: array<string, mixed>, annotations: array<string, bool>}> */
    public function definitions(): array;

    /**
     * Run one of {@see definitions()} (the server checks the name first).
     *
     * @param  array<string, mixed>  $args
     * @return array<mixed>|string a JSON result, or text returned verbatim
     *
     * @throws ToolError
     */
    public function run(string $name, array $args): array|string;
}
