<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * WI-G8: pin the documented API surface against the real routes so future drift
 * fails CI. Tolerant / list-based: it asserts every endpoint advertised by the
 * self-describing /api/v1 index actually resolves to a registered route
 * (param-name-agnostic), catching a doc that outlived its endpoint. It does NOT
 * require every route to be documented — that would be brittle.
 */
class ApiDocsDriftTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
    }

    /** Structural signature of a URI: param names collapsed so {id}=={store}. */
    private function signature(string $method, string $uri): string
    {
        $uri = ltrim($uri, '/');
        $uri = preg_replace('/\{[^}]+\}/', '{}', $uri);

        return strtoupper($method).' '.$uri;
    }

    public function test_every_documented_api_endpoint_resolves_to_a_route(): void
    {
        $res = $this->getJson('/api/v1');
        $res->assertOk();
        $index = $res->json();
        $this->assertArrayHasKey('endpoints', $index, 'the /api/v1 index must advertise endpoints');

        // Real registered routes, by structural signature.
        $registered = [];
        foreach (Route::getRoutes() as $route) {
            foreach ($route->methods() as $m) {
                $registered[$this->signature($m, $route->uri())] = true;
            }
        }

        $missing = [];
        foreach ($index['endpoints'] as $group => $lines) {
            foreach ((array) $lines as $line) {
                // "GET    /api/v1/stores/{id}/menu"
                if (! preg_match('/^\s*([A-Z]+)\s+(\S+)/', $line, $m)) {
                    continue;
                }
                $sig = $this->signature($m[1], $m[2]);
                if (! isset($registered[$sig])) {
                    $missing[] = "[{$group}] {$m[1]} {$m[2]}";
                }
            }
        }

        $this->assertSame([], $missing, "Documented API endpoints with no matching route (stale docs):\n".implode("\n", $missing));
    }

    public function test_developer_docs_hub_and_llms_txt_respond(): void
    {
        // The public developer surfaces must not 500 (they feed the docs corpus).
        $this->get('/api/v1')->assertOk();
        $this->get('/llms.txt')->assertOk();
    }
}
