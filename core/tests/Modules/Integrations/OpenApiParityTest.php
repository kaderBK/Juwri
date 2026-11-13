<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use Juwri\Modules\Integrations\Services\Api\OpenApiGenerator;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * A3 — the OpenAPI 3.1 document covers every api.v1.* route (so a new endpoint cannot
 * ship undocumented), and both the discovery index and the spec are served.
 */
class OpenApiParityTest extends TestCase
{
    public function test_spec_is_valid_openapi_31(): void
    {
        $spec = OpenApiGenerator::spec();

        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertNotEmpty($spec['paths']);
        $this->assertArrayHasKey('ApiToken', $spec['components']['securitySchemes']);
    }

    /**
     * A stricter 3.1 structural gate than the version-string check above: the
     * required top-level objects exist, and every operation carries the fields an
     * OpenAPI 3.1 consumer relies on (operationId, tags, at least one response).
     * Catches a generator regression that would emit a document a validator rejects.
     */
    public function test_spec_document_is_structurally_valid_openapi_31(): void
    {
        $spec = OpenApiGenerator::spec();

        // Required top-level objects (OpenAPI 3.1 §4).
        $this->assertSame('3.1.0', $spec['openapi']);
        foreach (['title', 'version'] as $k) {
            $this->assertArrayHasKey($k, $spec['info'], "info.{$k} is required");
            $this->assertNotSame('', (string) $spec['info'][$k]);
        }
        $this->assertNotEmpty($spec['servers'][0]['url'] ?? null, 'a server URL is required');

        $operationIds = [];
        foreach ($spec['paths'] as $path => $ops) {
            $this->assertIsString($path);
            foreach ($ops as $verb => $op) {
                $this->assertContains(
                    $verb,
                    ['get', 'post', 'put', 'patch', 'delete'],
                    "Unexpected HTTP verb {$verb} on {$path}"
                );
                // Each operation object must be well-formed and uniquely identified.
                $this->assertArrayHasKey('operationId', $op, "{$verb} {$path} missing operationId");
                $this->assertArrayHasKey('responses', $op, "{$verb} {$path} missing responses");
                $this->assertNotEmpty($op['responses'], "{$verb} {$path} has no responses");
                $this->assertNotContains(
                    $op['operationId'],
                    $operationIds,
                    "Duplicate operationId {$op['operationId']} — must be unique across the document"
                );
                $operationIds[] = $op['operationId'];

                // Path templating: every {param} in the URI has a path parameter.
                if (preg_match_all('/\{(\w+)\}/', $path, $m)) {
                    $declared = array_column($op['parameters'] ?? [], 'name');
                    foreach ($m[1] as $param) {
                        $this->assertContains(
                            $param,
                            $declared,
                            "{$verb} {$path} does not declare path parameter {$param}"
                        );
                    }
                }
            }
        }
    }

    /**
     * The live `api.v1.*` route set as `METHOD /uri` signatures (excluding meta).
     *
     * @return array<string, bool>
     */
    private function liveRouteSignatures(): array
    {
        $meta = ['api.v1.index', 'api.v1.openapi'];
        $live = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $name = (string) $route->getName();
            if ($name === '' || ! str_starts_with($name, 'api.v1.') || in_array($name, $meta, true)) {
                continue;
            }
            $path = '/' . ltrim($route->uri(), '/');
            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }
                $live[strtolower($method) . ' ' . $path] = true;
            }
        }

        return $live;
    }

    /**
     * The generated spec's `METHOD /uri` signatures.
     *
     * @return array<string, bool>
     */
    private function specSignatures(): array
    {
        $spec = OpenApiGenerator::spec();
        $sigs = [];
        foreach ($spec['paths'] as $path => $ops) {
            foreach (array_keys($ops) as $verb) {
                $sigs[$verb . ' ' . $path] = true;
            }
        }

        return $sigs;
    }

    /**
     * Direction 1 — no undocumented route: every live api/v1 route (method+uri)
     * has a matching spec entry. A new endpoint cannot ship without appearing in
     * the OpenAPI document.
     */
    public function test_every_api_v1_route_has_a_spec_path_and_method(): void
    {
        $missing = array_keys(array_diff_key($this->liveRouteSignatures(), $this->specSignatures()));

        $this->assertSame(
            [],
            $missing,
            "Live api/v1 routes with no OpenAPI entry:\n - " . implode("\n - ", $missing)
        );
    }

    /**
     * Direction 2 — no stale spec entry: every spec path+method resolves to a live
     * api/v1 route. Together with direction 1 this pins the OpenAPI document 1:1 to
     * the router (the parity invariant §8 P2 requires), so drift in EITHER direction
     * fails CI. (The generator builds the spec FROM the router, so this also guards
     * against a future generator change that invents or duplicates a path.)
     */
    public function test_spec_has_no_stale_paths(): void
    {
        $stale = array_keys(array_diff_key($this->specSignatures(), $this->liveRouteSignatures()));

        $this->assertSame(
            [],
            $stale,
            "OpenAPI entries with no matching live route:\n - " . implode("\n - ", $stale)
        );
    }

    /**
     * The set equality both tests above enforce, asserted as a single set compare
     * for a clear failure message — mirrors the F6-6 sweep's set-diff idiom.
     */
    public function test_spec_paths_equal_live_routes_both_directions(): void
    {
        $live = array_keys($this->liveRouteSignatures());
        $spec = array_keys($this->specSignatures());
        sort($live);
        sort($spec);

        $this->assertSame($spec, $live, 'The OpenAPI document and the live api/v1 route table have diverged.');
    }

    /**
     * HPMS E11 shipped new v1 endpoints (staff/driver push device registration +
     * staff TOTP enrolment); pin them explicitly so a regression that dropped them
     * from the router OR the spec is named, not just counted.
     */
    public function test_e11_endpoints_are_documented(): void
    {
        $spec = $this->specSignatures();
        foreach ([
            'post /api/v1/staff/push/register-device',
            'post /api/v1/staff/push/unregister-device',
            'post /api/v1/staff/2fa/setup',
            'post /api/v1/staff/2fa/confirm',
            'post /api/v1/driver/push/register-device',
            'post /api/v1/driver/push/unregister-device',
        ] as $sig) {
            $this->assertArrayHasKey($sig, $spec, "E11 endpoint missing from the OpenAPI document: {$sig}");
        }
    }

    public function test_discovery_index_and_openapi_endpoints_respond(): void
    {
        $this->getJson('/api/v1')
            ->assertOk()
            ->assertJsonStructure(['name', 'version', 'openapi', 'endpoints']);

        $this->getJson('/api/v1/openapi.json')
            ->assertOk()
            ->assertJsonPath('openapi', '3.1.0');
    }

    public function test_discovery_index_includes_previously_missing_groups(): void
    {
        // The router build surfaces the driver-fleet routes the hand-list omitted.
        $index = OpenApiGenerator::discoveryIndex();
        $this->assertArrayHasKey('driver', $index);
    }
}
