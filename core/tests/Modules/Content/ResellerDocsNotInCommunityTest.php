<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Modules\Content\Services\DevDocs\DocsCorpus;
use Tests\CommunityTestCase;

/**
 * Reseller operator guides are an edition feature. A Community install must not
 * ship them, and the public Dev MCP corpus must not list them.
 */
class ResellerDocsNotInCommunityTest extends CommunityTestCase
{
    public function test_the_public_corpus_does_not_list_reseller_operator_guides(): void
    {
        $paths = DocsCorpus::paths();

        $this->assertNotContains('resellers', DocsCorpus::pillars());
        $this->assertFalse((bool) collect($paths)->first(fn ($p) => str_starts_with($p, 'resellers/')));
        $this->assertNull(DocsCorpus::get('resellers/onboarding'));
        $this->assertNull(DocsCorpus::get('api/reseller-api'));
    }

    public function test_reseller_operator_docs_are_not_in_the_community_tree(): void
    {
        $repo = dirname(base_path());

        $this->assertDirectoryDoesNotExist(resource_path('docs/resellers'));
        $this->assertFileDoesNotExist(resource_path('docs/dev/api/reseller-api.md'));
        $this->assertFileDoesNotExist($repo.'/RESELLER-ONBOARDING.md');

        $devIndex = (string) file_get_contents(resource_path('docs/dev/README.md'));
        $this->assertStringNotContainsString('reseller-api.md', $devIndex);

        $services = (string) file_get_contents(resource_path('docs/internal/services-map.md'));
        $this->assertStringNotContainsString('../resellers/overview.md', $services);
    }
}
