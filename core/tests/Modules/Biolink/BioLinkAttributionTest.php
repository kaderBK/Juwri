<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink;

use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Core\Services\EntitlementWriter;
use Juwri\Modules\Biolink\Models\BioBlock;
use Juwri\Modules\Biolink\Models\BioClick;
use Tests\TestCase;

/**
 * BIOLINK-RUN A9 / G5: source attribution. The bio page classifies the traffic
 * source ONCE on the initial GET (qr-id / ?src / referrer → qr,social,direct,
 * share,other), stamps it into the page, and the click beacon echoes the same
 * value — so a view and its clicks share one source (the beacon's own referrer
 * is the bio page, which is why it cannot re-derive it).
 */
class BioLinkAttributionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([PreventRequestForgery::class]);
    }

    private function enabledStore(): Store
    {
        $store = Store::factory()->create();
        config(['settings.admin_allow_biolink' => true]);
        EntitlementWriter::grant($store->user, 'biolink');
        StoreOption::updateStoreOption($store->id, 'bio_settings', json_encode(['enabled' => true]));
        StoreOption::flushOptionMemo();

        return $store;
    }

    private function viewSource(Store $store): ?string
    {
        return BioClick::where('store_id', $store->id)->where('block_id', BioClick::VIEW_SENTINEL)->value('source');
    }

    public function test_social_referrer_is_classified_social(): void
    {
        $store = $this->enabledStore();

        $this->withHeaders(['referer' => 'https://www.instagram.com/thestore'])->get("/{$store->slug}/bio")->assertSeeHtml('data-bio-source="social"');

        $this->assertSame('social', $this->viewSource($store));
    }

    public function test_explicit_src_share_wins(): void
    {
        $store = $this->enabledStore();

        $this->get("/{$store->slug}/bio?src=share")->assertSeeHtml('data-bio-source="share"');
        $this->assertSame('share', $this->viewSource($store));
    }

    public function test_no_referrer_is_direct(): void
    {
        $store = $this->enabledStore();

        $this->get("/{$store->slug}/bio")->assertSeeHtml('data-bio-source="direct"');
        $this->assertSame('direct', $this->viewSource($store));
    }

    public function test_unknown_external_referrer_is_other(): void
    {
        $store = $this->enabledStore();

        $this->withHeaders(['referer' => 'https://some-random-blog.example/post'])
            ->get("/{$store->slug}/bio");

        $this->assertSame('other', $this->viewSource($store));
    }

    public function test_g5_source_threads_from_page_view_to_click(): void
    {
        $store = $this->enabledStore();
        $block = BioBlock::factory()->create(['store_id' => $store->id]);

        // page view from a social referrer → view row source 'social'
        $this->withHeaders(['referer' => 'https://tiktok.com/@store'])->get("/{$store->slug}/bio");
        $this->assertSame('social', $this->viewSource($store));

        // the beacon echoes the same stamped source
        $this->postJson("/{$store->slug}/bio/c/{$block->id}", ['src' => 'social'])->assertStatus(204);

        $click = BioClick::where('store_id', $store->id)->where('block_id', $block->id)->first();
        $this->assertSame('social', $click->source);
    }
}
