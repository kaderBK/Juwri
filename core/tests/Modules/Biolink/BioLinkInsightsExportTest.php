<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\EntitlementWriter;
use Juwri\Modules\Biolink\Models\BioBlock;
use Juwri\Modules\Biolink\Models\BioClick;
use Tests\TestCase;

/**
 * bio-t15 Lane D — SPEC §7 D4 (CSV export route/controller) + D5 (insights
 * view rendering the country/referrer breakdown cards). HTTP-level, through
 * the real route/middleware/controller — never a Blade-direct render (the
 * "blade-direct tests miss controller contract" trap).
 */
class BioLinkInsightsExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['settings.admin_allow_biolink' => true]);
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $this->shareLayoutGlobals(['currency_sign' => '$', 'currency_pos' => 1]);

        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    /** @return array{0: User, 1: Store} */
    private function store(): array
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now(), 'user_type' => 'user']);
        $title = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1',
        ]);
        EntitlementWriter::grant($owner, 'biolink');

        return [$owner, $store];
    }

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

    private function grantAnalyticsPro(User $owner): void
    {
        $this->seedProFeatureRow();
        config(['settings.admin_allow_bio_analytics_pro' => true]);
        EntitlementWriter::grant($owner, 'bio_analytics_pro');
    }

    /* ------------------------------------------------------------ route + gating */

    public function test_export_requires_auth(): void
    {
        $this->get(route('biolink.insights.export'))->assertRedirect(route('login'));
    }

    public function test_export_403s_without_bio_analytics_pro_entitlement(): void
    {
        [$owner] = $this->store();
        $this->seedProFeatureRow(); // key exists (kill-switch could even be on) — but no grant

        $this->actingAs($owner)->get(route('biolink.insights.export'))->assertStatus(403);
    }

    public function test_export_is_wide_open_pre_b9_when_no_feature_row_exists_yet(): void
    {
        // REGRESSION PIN, not a desired behavior: SPEC §8's documented gotcha in
        // action. With NO `bio_analytics_pro` row at all (B9, a parallel Lane B WI,
        // not yet merged into this branch), FeatureRegistry::isEnabled() defaults
        // an unknown key to true AND EnsureFeatureEnabled::planGated() defaults an
        // unknown key to false — so FeatureAccess::storeAllows() short-circuits
        // straight to `true` (kill-switch "on" + "not plan-gated" = wide open) with
        // NO entitlement check at all. This is exactly why SPEC calls B9's ship
        // migration "the single most important non-obvious gotcha in the run": once
        // it lands (inserting the row default-OFF, plan_gated=true), this same
        // unentitled store correctly flips to 403 — see
        // test_export_403s_without_bio_analytics_pro_entitlement() above, which pins
        // the POST-B9 correct behavior with the row seeded.
        //
        // bio-t15 F0 integration: B9 has now merged, so its migration ALWAYS seeds the
        // row (RefreshDatabase runs it) — the "no row at all" precondition can only be
        // recreated by deleting it. We do exactly that here to keep this documenting the
        // fails-open gotcha; the secure POST-B9 default (row present, unentitled → 403)
        // is the one that actually ships and is pinned by the test above.
        [$owner] = $this->store();
        DB::table('features')->where('key', 'bio_analytics_pro')->delete();

        $this->actingAs($owner)->get(route('biolink.insights.export'))->assertStatus(200);
    }

    public function test_export_streams_csv_with_expected_rows_when_entitled(): void
    {
        [$owner, $store] = $this->store();
        $this->grantAnalyticsPro($owner);

        $block = BioBlock::factory()->create(['store_id' => $store->id, 'label' => 'Instagram']);
        BioClick::create([
            'store_id' => $store->id, 'block_id' => $block->id, 'day' => now()->toDateString(),
            'source' => 'social', 'device' => 'mobile', 'country' => 'US', 'ref' => 'instagram.com', 'count' => 4,
        ]);

        $res = $this->actingAs($owner)->get(route('biolink.insights.export', ['range' => '30d']));

        $res->assertStatus(200);
        $this->assertStringContainsString('text/csv', (string) $res->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $res->headers->get('Content-Disposition'));

        $content = $res->streamedContent();
        $this->assertStringContainsString('Day,Block,Source,Device,Country,Referrer,Count', $content);
        $this->assertStringContainsString('Instagram', $content);
        $this->assertStringContainsString('US', $content);
        $this->assertStringContainsString('instagram.com', $content);
        $this->assertStringContainsString(',4', $content);
    }

    /** gap-fix: the CSV export must never leak another tenant's click rows. */
    public function test_export_is_tenant_isolated(): void
    {
        [$ownerA, $postA] = $this->store();
        [, $postB] = $this->store();
        $this->grantAnalyticsPro($ownerA); // global kill-switch → both stores are "entitled"

        $blockA = BioBlock::factory()->create(['store_id' => $postA->id, 'label' => 'AlphaBlock']);
        $blockB = BioBlock::factory()->create(['store_id' => $postB->id, 'label' => 'BetaBlock']);
        BioClick::create(['store_id' => $postA->id, 'block_id' => $blockA->id, 'day' => now()->toDateString(),
            'source' => 'direct', 'device' => 'desktop', 'country' => 'US', 'ref' => 'other', 'count' => 3]);
        BioClick::create(['store_id' => $postB->id, 'block_id' => $blockB->id, 'day' => now()->toDateString(),
            'source' => 'direct', 'device' => 'desktop', 'country' => 'GB', 'ref' => 'other', 'count' => 7]);

        $content = $this->actingAs($ownerA)->get(route('biolink.insights.export', ['range' => '30d']))
            ->assertStatus(200)->streamedContent();

        $this->assertStringContainsString('AlphaBlock', $content, 'own store rows present');
        $this->assertStringNotContainsString('BetaBlock', $content, 'another tenant\'s rows must never appear');
    }

    public function test_export_ignores_a_bogus_range_and_falls_back_to_7d(): void
    {
        [$owner] = $this->store();
        $this->grantAnalyticsPro($owner);

        $this->actingAs($owner)->get(route('biolink.insights.export', ['range' => 'bogus']))->assertStatus(200);
    }

    /* ------------------------------------------------------------ insights view */

    public function test_insights_page_shows_locked_upsell_for_non_entitled_store(): void
    {
        [$owner] = $this->store();
        $this->seedProFeatureRow(); // row exists (plan_gated, default OFF) — this owner has no grant

        $res = $this->actingAs($owner)->get(route('biolink.insights'));

        $res->assertStatus(200);
        $res->assertSee(___('Countries'));
        $res->assertSee(___('Top referrers'));
        $res->assertSee(___('Upgrade to see visitor countries'));
        $res->assertDontSee(___('Export CSV'));
    }

    public function test_insights_page_shows_breakdown_and_export_button_when_entitled(): void
    {
        [$owner, $store] = $this->store();
        $this->grantAnalyticsPro($owner);

        BioClick::create([
            'store_id' => $store->id, 'block_id' => BioClick::VIEW_SENTINEL, 'day' => now()->toDateString(),
            'source' => 'direct', 'device' => 'mobile', 'country' => 'US', 'ref' => 'instagram.com', 'count' => 1,
        ]);

        $res = $this->actingAs($owner)->get(route('biolink.insights'));

        $res->assertStatus(200);
        $res->assertSee(___('Export CSV'));
        $res->assertSee('instagram.com');
        $res->assertDontSee(___('Upgrade to see visitor countries'));
    }
}
