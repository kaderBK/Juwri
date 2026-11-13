<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Platform P5: machine-readable /llms.txt for AI agents. */
class LlmsDocsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([\App\Http\Middleware\Installed::class]);
    }

    public function test_llms_txt_is_served(): void
    {
        $res = $this->get(route('llms'))->assertOk();
        $this->assertStringContainsString('text/plain', (string) $res->headers->get('Content-Type'));
        $res->assertSeeHtml('/api/v1/storefront/menu')->assertSeeHtml('X-Storefront-Token')->assertSeeHtml('client_uuid');
    }
}
