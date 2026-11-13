<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use Juwri\Modules\Integrations\Services\Api\OpenApiGenerator;
use Tests\TestCase;

/**
 * P4-17 follow-up: the OpenAPI document's server + path must be a real URL. The path keys are absolute
 * (`/api/v1/...`), so the server is the site root; with `/api/v1` as the server every client (ZAP's API scan
 * included) called `/api/v1/api/v1/...` and got 404s.
 */
class OpenApiServerUrlTest extends TestCase
{
    public function test_server_plus_path_is_the_live_url(): void
    {
        $spec = OpenApiGenerator::spec();
        $server = rtrim((string) $spec['servers'][0]['url'], '/');
        $this->assertSame(rtrim(url('/'), '/'), $server);
        foreach (array_keys($spec['paths']) as $path) {
            $this->assertStringStartsWith('/api/v1', $path);
            $this->assertStringNotContainsString('/api/v1/api/v1', $server.$path);
        }
        $this->getJson(parse_url($server.'/api/v1/openapi.json', PHP_URL_PATH))->assertOk();
    }
}
