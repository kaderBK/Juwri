<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Tests\CommunityTestCase;

/**
 * Aggregator commission presets exist for the platform marketing calculator.
 * A Community install does not ship that table.
 */
class CommissionDefsNotInCommunityTest extends CommunityTestCase
{
    public function test_commission_presets_are_not_in_the_community_tree(): void
    {
        $this->assertFileDoesNotExist(resource_path('views/marketing/data/commission-defs.php'));
    }
}
