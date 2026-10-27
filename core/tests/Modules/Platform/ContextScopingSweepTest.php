<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Tests\TestCase;

/**
 * P2-5 — context-scoping sweep (batch 1). The converted self-selecting pages must
 * use the shared write-through selector (partials/context-store-select) instead of
 * a page-local ?store_id GET select, so page + sidebar can never disagree.
 */
class ContextScopingSweepTest extends TestCase
{
    /** Pages converted in this batch (controllers already follow the session context), relative to resources/views. */
    private const CONVERTED = [
        'owner/dispatch/index',
        'owner/integrations/aggregators',
        'owner/integrations/posbridge/foodics',
        'owner/integrations/posbridge/marn',
        'owner/marketing/giftcards',
        'owner/phone-agent/index',
        'owner/gbp/index',
        'owner/kiosk/index',
        'owner/food-safety/index',
        'owner/tables/reservations',
        'owner/signage/index',
        'owner/orders/waiter',
        'owner/catering/index',
    ];

    public function test_converted_pages_use_the_shared_write_through_selector(): void
    {
        foreach (self::CONVERTED as $rel) {
            $blade = file_get_contents(base_path('resources/views/'.$rel.'.blade.php'));

            $this->assertStringContainsString(
                'partials.context-store-select', $blade,
                "$rel should include the shared write-through selector"
            );
            // The old page-local ?store_id GET select must be gone from page_actions.
            $this->assertStringNotContainsString(
                'name="store_id" class="form-select', $blade,
                "$rel still has a page-local store_id select"
            );
        }
    }
}
