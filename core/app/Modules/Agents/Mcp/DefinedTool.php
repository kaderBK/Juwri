<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Mcp;

use Laravel\Mcp\Server\Tool;

/**
 * P1-16: a laravel/mcp Tool primitive from a tool definition array `{name, description, inputSchema, annotations}` —
 * the tools of the catalog, customer and dev servers, listed exactly as those servers always advertised them
 * (their own definition lists, {@see ToolSetServer}). The server runs the tool by name.
 */
final class DefinedTool extends Tool
{
    /** @param  array{name: string, description: string, inputSchema: array<string, mixed>, annotations: array<string, bool>}  $definition */
    public function __construct(private readonly array $definition)
    {
        $this->name = $definition['name'];
        $this->description = $definition['description'];
    }

    /**
     * The definition array of a tool whose inputs are flat typed properties (the old `tool()` helpers).
     *
     * @param  array<string, string>  $props  property => JSON type
     * @param  list<string>  $required
     * @param  array<string, bool>  $annotations  MCP tool hints (default: read-only)
     * @return array{name: string, description: string, inputSchema: array<string, mixed>, annotations: array<string, bool>}
     */
    public static function define(string $name, string $description, array $props, array $required = [], array $annotations = ['readOnlyHint' => true]): array
    {
        $properties = [];
        foreach ($props as $key => $type) {
            $properties[$key] = ['type' => $type];
        }

        return [
            'name' => $name,
            'description' => $description,
            'inputSchema' => ['type' => 'object', 'properties' => (object) $properties, 'required' => $required],
            'annotations' => $annotations,
        ];
    }

    /** @return array{name: string, description: string, inputSchema: array<string, mixed>, annotations: array<string, bool>} */
    public function toArray(): array
    {
        return $this->definition;
    }
}
