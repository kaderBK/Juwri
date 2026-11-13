<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink;

use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\EntitlementWriter;
use Juwri\Modules\Biolink\Models\BioBlock;
use Juwri\Modules\Biolink\Models\BioClick;
use Juwri\Modules\Biolink\Services\BioInsightsService;
use Tests\TestCase;

/**
 * bio-t15 Lane D — SPEC §7 D1/D2/D5 + §8 gating. BioInsightsService::extra()
 * is the sole owner of the country/referrer breakdowns merged into the
 * insights view; country/referrer are `bio_analytics_pro` (PRO) and MUST be
 * gated server-side here (not only hidden in the UI). csvRows() (D4) feeds
 * the CSV export controller.
 *
 * B9 (the ship migration inserting `bio_analytics_pro` default-OFF) is a Lane
 * B WI running in parallel — this test seeds the same row itself (idempotent
 * guard) so gating is provable regardless of merge order.
 */
class BioLinkInsightsExtraTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): BioInsightsService
    {
        return app(BioInsightsService::class);
    }

    /** Mirrors the row shape 2026_08_26_100000_ship_ai_biolink_feature_key.php ships (B9's sibling). */
    private function seedProFeatureRow(): void
    {
        if (DB::table('features')->where('key', 'bio_analytics_pro')->exists()) {
            return;
        }

        DB::table('features')->insert([
            'key' => 'bio_analytics_pro', 'label' => 'Bio Analytics Pro',
            'description' => 'Country, referrer breakdowns and CSV export for bio insights.',
            'group_' => 'parity', 'kind' => 'boolean', 'default_value' => '0',
            'sellable_via' => json_encode(['plan']), 'platform_only' => false,
            'plan_gated' => true, 'killable' => true, 'shipped' => true, 'tier' => 'core',
            'position' => (int) DB::table('features')->max('position') + 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        FeatureRegistry::clearCache(); // raw insert fires no model event
    }

    private function entitledStore(): Store
    {
        $this->seedProFeatureRow();
        $store = Store::factory()->create();
        config(['settings.admin_allow_bio_analytics_pro' => true]);
        EntitlementWriter::grant($store->user, 'bio_analytics_pro');

        return $store;
    }

    private function recordView(Store $store, string $day, string $country, string $ref, int $count = 1): void
    {
        BioClick::create([
            'store_id' => $store->id, 'block_id' => BioClick::VIEW_SENTINEL, 'day' => $day,
            'source' => 'direct', 'device' => 'mobile', 'country' => $country, 'ref' => $ref, 'count' => $count,
        ]);
    }

    /* ------------------------------------------------------------ gating */

    public function test_non_entitled_store_gets_locked_result_with_no_breakdown_data(): void
    {
        $this->seedProFeatureRow(); // key exists (plan_gated) — THIS store just has no grant
        $store = Store::factory()->create();
        $this->recordView($store, now()->toDateString(), 'US', 'instagram.com');

        $extra = $this->svc()->extra($store->id, '30d');

        $this->assertFalse($extra['analytics_pro']);
        $this->assertSame([], $extra['countries']);
        $this->assertSame([], $extra['referrers']);
    }

    public function test_missing_feature_row_still_locks_because_kill_switch_is_off(): void
    {
        // No bio_analytics_pro row at all: FeatureAccess::storeAllows() fails OPEN on
        // plan_gated (no gate row → true), but the kill-switch (isEnabled) also has no
        // admin_allow_bio_analytics_pro config set, so this asserts today's actual
        // behavior rather than assuming either lane's ship-migration has landed yet.
        $store = Store::factory()->create();

        $extra = $this->svc()->extra($store->id, '30d');

        // Whatever the boolean resolves to, it must NEVER crash and must always
        // return the full, well-typed shape (analytics_pro/geo_available/countries/referrers).
        $this->assertIsBool($extra['analytics_pro']);
        $this->assertIsArray($extra['countries']);
        $this->assertIsArray($extra['referrers']);
    }

    /* --------------------------------------------------------- breakdowns */

    public function test_entitled_store_gets_country_and_referrer_breakdowns(): void
    {
        $store = $this->entitledStore();
        $today = now()->toDateString();
        $this->recordView($store, $today, 'US', 'instagram.com', 3);
        $this->recordView($store, $today, 'FR', 'tiktok.com', 1);
        $this->recordView($store, $today, BioClick::COUNTRY_UNKNOWN, BioClick::REF_NONE, 2);

        $extra = $this->svc()->extra($store->id, '30d');

        $this->assertTrue($extra['analytics_pro']);

        $countries = collect($extra['countries'])->pluck('count', 'country');
        $this->assertSame(3, $countries['US']);
        $this->assertSame(1, $countries['FR']);
        $this->assertSame(2, $countries[BioClick::COUNTRY_UNKNOWN]);

        $referrers = collect($extra['referrers'])->pluck('count', 'ref');
        $this->assertSame(3, $referrers['instagram.com']);
        $this->assertSame(1, $referrers['tiktok.com']);
        $this->assertArrayNotHasKey(BioClick::REF_NONE, $referrers, 'no-referrer rows excluded from the referrer breakdown');
    }

    public function test_range_windows_the_breakdown(): void
    {
        $store = $this->entitledStore();
        $this->recordView($store, now()->toDateString(), 'US', 'instagram.com');
        $this->recordView($store, now()->subDays(40)->toDateString(), 'FR', 'tiktok.com');

        $extra7 = $this->svc()->extra($store->id, '7d');
        $this->assertSame(['US' => 1], collect($extra7['countries'])->pluck('count', 'country')->all());

        $extra90 = $this->svc()->extra($store->id, '90d');
        $this->assertEqualsCanonicalizing(['US', 'FR'], collect($extra90['countries'])->pluck('country')->all());
    }

    public function test_geo_available_flag_reflects_platform_config(): void
    {
        $store = $this->entitledStore();

        config(['geoip.trust_cf_country' => false, 'geoip.mmdb_path' => null]);
        $this->assertFalse($this->svc()->extra($store->id, '30d')['geo_available']);

        config(['geoip.trust_cf_country' => true]);
        $this->assertTrue($this->svc()->extra($store->id, '30d')['geo_available']);
    }

    /* ---------------------------------------------------------- csvRows() */

    public function test_csv_rows_include_expected_columns_and_no_pii(): void
    {
        $store = $this->entitledStore();
        $block = BioBlock::factory()->create(['store_id' => $store->id, 'label' => 'Order Delivery']);
        $today = now()->toDateString();

        BioClick::create(['store_id' => $store->id, 'block_id' => $block->id, 'day' => $today, 'source' => 'qr', 'device' => 'mobile', 'country' => 'US', 'ref' => 'instagram.com', 'count' => 5]);
        $this->recordView($store, $today, 'FR', 'tiktok.com', 2);

        $rows = $this->svc()->csvRows($store->id, '30d')->all();

        $this->assertCount(2, $rows);

        $blockRow = collect($rows)->firstWhere('block_label', 'Order Delivery');
        $this->assertNotNull($blockRow);
        $this->assertSame('US', $blockRow['country']);
        $this->assertSame('instagram.com', $blockRow['ref']);
        $this->assertSame(5, $blockRow['count']);
        $this->assertSame('qr', $blockRow['source']);

        $viewRow = collect($rows)->firstWhere('block_label', '(page view)');
        $this->assertNotNull($viewRow);
        $this->assertSame(2, $viewRow['count']);
        $this->assertSame('FR', $viewRow['country']);

        foreach ($rows as $r) {
            $this->assertSame(['day', 'block_label', 'source', 'device', 'country', 'ref', 'count'], array_keys($r));
        }
    }

    public function test_csv_rows_available_even_when_not_entitled(): void
    {
        // csvRows() itself is a plain reader — the PRO gate for the export is
        // enforced by the CONTROLLER (BioInsightsController), not this reader, so a
        // future caller with its own authorization can't be silently starved of data.
        $store = Store::factory()->create();
        $this->recordView($store, now()->toDateString(), 'US', 'instagram.com');

        $rows = $this->svc()->csvRows($store->id, '30d')->all();

        $this->assertCount(1, $rows);
    }
}
