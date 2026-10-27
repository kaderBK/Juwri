<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Modules\Content\Http\Controllers\IntegrationsDocsController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WI-G8: pin the integrations-doc surface. Every whitelisted slug must resolve
 * to a real markdown file that renders — a doc removed or a slug added without
 * its file fails CI. The whitelist also blocks arbitrary reads.
 */
class IntegrationsDocsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([\App\Http\Middleware\Installed::class]);
    }

    /** @return array<int,string> the whitelisted slugs, read from the controller. */
    private function slugs(): array
    {
        $ref = new \ReflectionClass(IntegrationsDocsController::class);
        $docs = $ref->getConstant('DOCS');

        return array_keys($docs);
    }

    public function test_every_integration_doc_slug_renders(): void
    {
        $slugs = $this->slugs();
        $this->assertNotEmpty($slugs, 'there is at least one integration doc');

        foreach ($slugs as $slug) {
            $res = $this->get(route('integrations.docs', $slug));
            $res->assertOk();
            $res->assertHeader('Content-Type', 'text/html; charset=utf-8');
            // The markdown rendered into a non-empty body.
            $this->assertNotEmpty(trim(strip_tags($res->getContent())), "doc {$slug} rendered empty");
        }
    }

    public function test_unknown_slug_is_rejected(): void
    {
        $this->get(route('integrations.docs', 'not-a-real-slug'))->assertNotFound();
        // Path-traversal attempt must not read arbitrary files.
        $this->get('integrations/docs/' . urlencode('../../.env'))->assertNotFound();
    }
}
