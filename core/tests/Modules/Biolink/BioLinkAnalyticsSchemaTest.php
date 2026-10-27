<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Models\Store;
use Juwri\Modules\Biolink\Models\BioClick;
use Tests\TestCase;

/**
 * bio-t15 Lane D — D1/D2: the `bio_clicks` schema migration
 * (2026_08_26_120000_add_country_ref_to_bio_clicks_table). Proves the columns
 * + safe defaults + the migrated UNIQUE grain directly via Eloquent `create()`
 * (never through BioLinkService::upsertCount(), a Lane A/B file outside this
 * lane's fence — see that migration's docblock + LEDGER "§ Contract changes"
 * for the follow-up F0 needs there).
 */
class BioLinkAnalyticsSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_bio_clicks_has_country_and_ref_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('bio_clicks', 'country'));
        $this->assertTrue(Schema::hasColumn('bio_clicks', 'ref'));
    }

    public function test_new_columns_default_to_the_no_data_sentinels(): void
    {
        $store = Store::factory()->create();

        BioClick::create([
            'store_id' => $store->id, 'block_id' => BioClick::VIEW_SENTINEL,
            'day' => now()->toDateString(), 'source' => 'direct', 'device' => 'mobile', 'count' => 1,
        ]);

        $row = BioClick::where('store_id', $store->id)->firstOrFail();
        $this->assertSame(BioClick::COUNTRY_UNKNOWN, $row->country, "unpopulated country defaults to the '--' sentinel");
        $this->assertSame(BioClick::REF_NONE, $row->ref, 'unpopulated ref defaults to the empty-string sentinel');
    }

    public function test_unique_grain_treats_different_countries_as_separate_rows(): void
    {
        $store = Store::factory()->create();
        $day = now()->toDateString();

        BioClick::create(['store_id' => $store->id, 'block_id' => 0, 'day' => $day, 'source' => 'direct', 'device' => 'mobile', 'country' => 'US', 'ref' => '', 'count' => 1]);
        BioClick::create(['store_id' => $store->id, 'block_id' => 0, 'day' => $day, 'source' => 'direct', 'device' => 'mobile', 'country' => 'FR', 'ref' => '', 'count' => 1]);

        $this->assertSame(
            2,
            BioClick::where('store_id', $store->id)->count(),
            'country is part of the migrated unique grain — two countries on the same block/day/source/device must NOT collapse into one row'
        );
    }

    public function test_unique_grain_still_rejects_a_true_full_duplicate(): void
    {
        $store = Store::factory()->create();
        $day = now()->toDateString();
        $row = ['store_id' => $store->id, 'block_id' => 0, 'day' => $day, 'source' => 'direct', 'device' => 'mobile', 'country' => 'US', 'ref' => 'instagram.com', 'count' => 1];

        BioClick::create($row);

        $this->expectException(QueryException::class);
        BioClick::create($row);
    }

    public function test_unique_grain_treats_different_referrers_as_separate_rows(): void
    {
        $store = Store::factory()->create();
        $day = now()->toDateString();

        BioClick::create(['store_id' => $store->id, 'block_id' => 0, 'day' => $day, 'source' => 'social', 'device' => 'mobile', 'country' => 'US', 'ref' => 'instagram.com', 'count' => 1]);
        BioClick::create(['store_id' => $store->id, 'block_id' => 0, 'day' => $day, 'source' => 'social', 'device' => 'mobile', 'country' => 'US', 'ref' => 'tiktok.com', 'count' => 1]);

        $this->assertSame(2, BioClick::where('store_id', $store->id)->count());
    }
}
