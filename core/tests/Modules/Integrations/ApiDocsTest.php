<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P1-17: dedoc/scramble OpenAPI 3.1 docs for api/v1 only. The docs routes are local-only by default; "public docs" is the
 * owner's call (API_DOCS_PUBLIC), off by default.
 */
class ApiDocsTest extends TestCase
{
    use RefreshDatabase;

    public function test_docs_are_refused_outside_local_by_default(): void
    {
        $this->assertFalse((bool) config('api.docs_public'));
        $this->assertFalse($this->app->environment('local'));
        $this->get('/docs/api')->assertForbidden();
        $this->get('/docs/api.json')->assertForbidden();
    }

    public function test_docs_open_in_the_local_environment(): void
    {
        $this->app['env'] = 'local';
        $this->get('/docs/api.json')->assertOk();
    }

    public function test_the_owner_can_make_them_public_with_one_switch(): void
    {
        config(['api.docs_public' => true]);
        $this->get('/docs/api.json')->assertOk();
        $this->get('/docs/api')->assertOk();
    }

    public function test_the_document_is_openapi_3_1_and_covers_api_v1_only(): void
    {
        config(['api.docs_public' => true]);
        $doc = $this->get('/docs/api.json')->assertOk()->json();

        $this->assertStringStartsWith('3.1', $doc['openapi']);
        $this->assertSame('juwri API v1', $doc['info']['title']);
        $this->assertNotEmpty($doc['paths'], 'api/v1 endpoints are documented');
        $this->assertStringEndsWith('/api/v1', $doc['servers'][0]['url']);
        $uris = collect(app('router')->getRoutes()->getRoutes())->map(fn ($r) => preg_replace('/\{[^}]*\}/', '{}', '/'.ltrim($r->uri(), '/')))->all();
        foreach (array_keys($doc['paths']) as $path) {
            $this->assertStringStartsNotWith('/api/', $path, 'paths are relative to the /api/v1 server');
            $this->assertContains(preg_replace('/\{[^}]*\}/', '{}', rtrim('/api/v1'.$path, '/')), $uris, "$path is a real api/v1 route");
        }
    }
}
