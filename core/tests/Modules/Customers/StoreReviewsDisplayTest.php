<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Modules\Storefront\Services\StoreReviewsDisplay;
use Tests\TestCase;

/**
 * Follow-up C1 — the per-store store-review DISPLAY gate.
 *
 * The whole point of the decouple is that an existing tenant that never opted
 * in sees NO behaviour change, so "absent option → false" is the load-bearing
 * case. '1' opts in; a stored '0' stays off (and must not read truthy under a
 * naive bool cast of the non-empty string).
 */
class StoreReviewsDisplayTest extends TestCase
{
    use RefreshDatabase;

    /** A real Post so the store_options FK is satisfied. */
    private function storeId(): int
    {
        return Store::factory()->create()->id;
    }

    public function test_absent_option_defaults_off(): void
    {
        $this->assertFalse(StoreReviewsDisplay::enabledFor($this->storeId()));
    }

    public function test_flag_one_opts_the_store_in(): void
    {
        $id = $this->storeId();
        StoreOption::updateStoreOption($id, 'show_store_reviews', '1');

        $this->assertTrue(StoreReviewsDisplay::enabledFor($id));
    }

    public function test_flag_zero_stays_off(): void
    {
        $id = $this->storeId();
        StoreOption::updateStoreOption($id, 'show_store_reviews', '0');

        $this->assertFalse(StoreReviewsDisplay::enabledFor($id));
    }
}
