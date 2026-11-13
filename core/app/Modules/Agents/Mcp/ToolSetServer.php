<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Mcp;

use Juwri\Modules\Agents\Http\Controllers\McpError;
use Juwri\Modules\Agents\Tools\ToolError;
use Laravel\Mcp\Server\Contracts\Transport;

/**
 * P1-16: a laravel/mcp server whose tools are its own fixed set ({@see McpToolSet}: definitions + one runner) — the
 * catalog, customer and dev servers, which share no tool with the agent tool registry. A tool's result is its JSON
 * (unescaped unicode/slashes) or, for a text tool (the dev docs), the text verbatim.
 */
abstract class ToolSetServer extends JsonRpcMcpServer
{
    public function __construct(Transport $transport, protected readonly McpToolSet $toolSet)
    {
        parent::__construct($transport);
        foreach ($toolSet->definitions() as $definition) {
            $this->tools[] = new DefinedTool($definition);
        }
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     *
     * @throws ToolError
     */
    public function callTool(string $name, array $args): array
    {
        if (! in_array($name, array_column($this->toolSet->definitions(), 'name'), true)) {
            throw new McpError(-32602, "Unknown tool: {$name}");
        }
        $out = $this->toolSet->run($name, $args);
        $text = is_string($out) ? $out : (string) json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return ['content' => [['type' => 'text', 'text' => $text]]];
    }
}
