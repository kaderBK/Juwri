<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\Legal;

use Juwri\Core\Legal\LegalDocuments;
use Tests\CommunityTestCase;

/**
 * The Free Reseller Terms are an edition click-through. A Community install
 * does not publish the document or ship the text.
 */
class FreeResellerTermsNotInCommunityTest extends CommunityTestCase
{
    public function test_the_free_reseller_terms_are_not_a_community_document(): void
    {
        config(['legal.enabled' => true]);

        $this->assertFalse(LegalDocuments::has('free_reseller'));
        $this->assertFileDoesNotExist(resource_path('legal/en/free_reseller.md'));
        $this->assertFileDoesNotExist(resource_path('legal/ar/free_reseller.md'));
        $this->get('/legal/free-reseller')->assertNotFound();
    }
}
