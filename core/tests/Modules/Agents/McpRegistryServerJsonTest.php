<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use Tests\TestCase;

/**
 * M3-1 — the committed registry descriptor `deploy/mcp/server.json` must be
 * valid JSON and list exactly the two PUBLIC servers (Catalog, Dev) over
 * streamable-http, in a reverse-DNS namespace, with NO token-auth server
 * leaking in. The actual `mcp-publisher` publish is an owner action (RUNBOOK).
 */
class McpRegistryServerJsonTest extends TestCase
{
    private function serverJsonPath(): string
    {
        // base_path() is the Laravel app root (core/); deploy/ is its sibling.
        return dirname(base_path()).'/deploy/mcp/server.json';
    }

    public function test_server_json_is_valid_and_lists_the_two_public_servers(): void
    {
        $path = $this->serverJsonPath();
        $this->assertFileExists($path);

        $doc = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($doc, 'server.json is not valid JSON');
        $this->assertIsArray($doc['servers'] ?? null);
        $this->assertCount(2, $doc['servers']);

        $urls = [];
        foreach ($doc['servers'] as $server) {
            $this->assertStringStartsWith('com.', $server['name']);
            $this->assertNotEmpty($server['description']);
            $this->assertNotEmpty($server['version']);
            $this->assertNotEmpty($server['remotes']);
            $remote = $server['remotes'][0];
            $this->assertSame('streamable-http', $remote['type']);
            $urls[] = $remote['url'];
        }

        $joined = implode(' ', $urls);
        $this->assertStringContainsString('/mcp/catalog', $joined);
        $this->assertStringContainsString('/mcp/dev', $joined);

        // Token-auth servers must NOT be advertised in the public registry v1.
        $this->assertStringNotContainsString('/mcp/storefront', $joined);
        $this->assertStringNotContainsString('/mcp/customer', $joined);
        foreach ($urls as $url) {
            $this->assertDoesNotMatchRegularExpression('#/mcp$#', $url, 'the bare /mcp Admin endpoint must not be listed');
        }
    }

    public function test_runbook_exists(): void
    {
        $this->assertFileExists(dirname(base_path()).'/deploy/mcp/RUNBOOK.md');
    }
}
