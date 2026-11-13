<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use App\Http\Controllers\DeveloperDocsController;
use Juwri\Core\Http\Middleware\AbuseRateLimit;
use App\Http\Middleware\Installed;
use Juwri\Modules\Content\Services\DevDocs\DocsCorpus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * Dev MCP P3-4: search quality + fetch round-trips. The `docs:build-reference
 * --check` suite pin already lives in DevMcpReferenceTest (P2-5).
 */
class DevMcpSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // D2-9 M1-2 grew the corpus from the dev pillar to every public pillar,
        // so the fetch_full_doc round-trip below issues 150+ POSTs in one test —
        // past the /mcp/dev 60/min throttle. Drop the rate-limit middleware here;
        // the round-trip CONTRACT (every path fetches to its exact body) is what
        // this suite pins, not the per-minute limit (covered by AbuseRateLimit tests).
        $this->withoutMiddleware([
            Installed::class,
            ThrottleRequests::class,
            AbuseRateLimit::class,
        ]);
    }

    private function toolText(string $tool, array $arguments = []): string
    {
        return $this->postJson('/mcp/dev', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ])->assertOk()->json('result.content.0.text');
    }

    public function test_searching_a_known_filter_name_ranks_the_filters_doc_first(): void
    {
        // Use a real whitelisted filter so this keeps working if the list changes.
        $filter = DeveloperDocsController::filterReference()[0];

        $text = $this->toolText('search_docs', ['query' => "{$filter} filter"]);

        $this->assertStringContainsString('## 1.', $text);
        $firstResult = explode('## 2.', explode('## 1.', $text)[1])[0];
        $this->assertStringContainsString('`themes/filters`', $firstResult);
    }

    public function test_search_finds_webhook_verification_guidance(): void
    {
        $text = $this->toolText('search_docs', ['query' => 'webhook signature verify']);
        $this->assertStringContainsString('`api/webhooks`', $text);
    }

    public function test_search_respects_max_results(): void
    {
        $text = $this->toolText('search_docs', ['query' => 'theme', 'max_results' => 2]);
        $this->assertStringContainsString('## 2.', $text);
        $this->assertStringNotContainsString('## 3.', $text);
    }

    public function test_search_with_no_matches_suggests_valid_paths(): void
    {
        $text = $this->toolText('search_docs', ['query' => 'zzqqxwv']);
        $this->assertStringContainsString('No matches', $text);
        $this->assertStringContainsString('themes/filters', $text);
    }

    public function test_search_requires_a_query(): void
    {
        $this->postJson('/mcp/dev', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'search_docs', 'arguments' => []],
        ])->assertOk()->assertJsonPath('error.code', -32602);
    }

    public function test_fetch_full_doc_round_trips_every_corpus_path(): void
    {
        $paths = DocsCorpus::paths();
        $this->assertNotEmpty($paths);

        foreach ($paths as $path) {
            $body = $this->toolText('fetch_full_doc', ['path' => $path]);
            $this->assertSame(DocsCorpus::get($path)['body'], $body, "round-trip failed for {$path}");
        }
    }

    public function test_fetch_full_doc_unknown_path_lists_valid_paths(): void
    {
        $res = $this->postJson('/mcp/dev', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'fetch_full_doc', 'arguments' => ['path' => 'nope/nada']],
        ])->assertOk();

        $res->assertJsonPath('error.code', -32602);
        $this->assertStringContainsString('api/webhooks', $res->json('error.message'));
    }
}
