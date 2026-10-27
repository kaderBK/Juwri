<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use App\Http\Middleware\Installed;
use Juwri\Modules\Content\Services\DevDocs\DocsCorpus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * D2-9 M1-2 — Dev MCP search now spans every PUBLIC help pillar (users,
 * integrations, resellers, shoppers) alongside the dev docs, while the
 * `internal/` pillar stays invisible. The dev pillar keeps its unprefixed
 * paths so the DevMcpSearchTest contracts are unaffected (asserted here too).
 */
class DevMcpPillarSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(Installed::class);
    }

    private function toolCall(string $tool, array $arguments = []): array
    {
        return $this->postJson('/mcp/dev', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ])->assertOk()->json();
    }

    public function test_corpus_enumerates_public_pillars_with_prefixes(): void
    {
        $paths = DocsCorpus::paths();

        // dev stays unprefixed (pinned contract).
        $this->assertContains('themes/filters', $paths);
        // other public pillars are prefixed.
        $this->assertContains('users/getting-started', $paths);
        $this->assertTrue((bool) collect($paths)->first(fn ($p) => str_starts_with($p, 'resellers/')), 'no resellers/ paths');
        // internal is NEVER enumerated.
        $this->assertEmpty(array_filter($paths, fn ($p) => str_starts_with($p, 'internal/')));
        $this->assertNotContains('internal', DocsCorpus::pillars());
    }

    public function test_get_resolves_pillar_paths_but_never_internal(): void
    {
        $this->assertNotNull(DocsCorpus::get('users/getting-started'));
        // internal docs exist on disk but are unreachable through the corpus.
        $this->assertFileExists(resource_path('docs/internal/security.md'));
        $this->assertNull(DocsCorpus::get('internal/security'));
    }

    public function test_search_surfaces_users_pillar_and_never_internal(): void
    {
        // The suite runs in the ar default locale, where DocsCorpus searches the
        // Arabic overlay; query the Arabic term ("القائمة" = menu) accordingly.
        $results = DocsCorpus::search('القائمة', 25);
        $docs = array_column($results, 'doc');

        $this->assertNotEmpty(array_filter($docs, fn ($d) => str_starts_with($d, 'users/')), 'expected a users/ hit for "القائمة"');
        $this->assertEmpty(array_filter($docs, fn ($d) => str_starts_with($d, 'internal/')), 'internal/ leaked into search');
    }

    public function test_pillar_filter_scopes_results(): void
    {
        $results = DocsCorpus::search('menu', 25, ['users']);
        $this->assertNotEmpty($results);
        foreach ($results as $r) {
            $this->assertStringStartsWith('users/', $r['doc']);
        }
    }

    public function test_search_docs_tool_accepts_pillar_and_rejects_internal(): void
    {
        // A users-scoped search via the MCP tool returns users/ docs.
        $text = $this->toolCall('search_docs', ['query' => 'menu', 'pillar' => 'users'])['result']['content'][0]['text'];
        $this->assertStringContainsString('users/', $text);

        // `internal` is not a valid pillar — the tool refuses it (never leaks).
        $err = $this->toolCall('search_docs', ['query' => 'menu', 'pillar' => 'internal']);
        $this->assertSame(-32602, $err['error']['code']);
        $this->assertStringNotContainsString('internal/', (string) $err['error']['message']);
    }
}
