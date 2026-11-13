<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink;

use Juwri\Modules\Biolink\Models\BioBlock;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Biolink\Services\BioLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Platform E2E v2 — A6 biolink public DEPTH (service level).
 *
 * NOTE — the public bio page (GET {slug}/bio) and its click beacon are both
 * gated by the GLOBAL `biolink` kill-switch, which ships DARK (FeatureRegistry::
 * isEnabled('biolink') === false). Enabling it platform-wide is an app-wide
 * setting change this run must not make, so the public HTTP render + en/ar
 * parity is BLOCKED-OWNER (see the ledger). This test covers the substantive
 * depth that does NOT require that flip, exercising BioLinkService directly:
 *   • many block types render through blocksFor without error;
 *   • the PRO-gated `tipjar` block is hidden without the bio_pro entitlement and
 *     shown with it (feature toggled TEST-LOCALLY via config, not the shared env);
 *   • inactive / schedule-hidden blocks are excluded from the public set;
 *   • click + view analytics land in bio_clicks and bump clicks_cached.
 */
class E2eV2A6BiolinkTest extends TestCase
{
    use RefreshDatabase;

    private function store(): Store
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'bio-'.rand(100000, 999999), 'color' => '#111',
            'title' => 'Bio Store', 'sub_title' => 'x', 'main_image' => 'a.png', 'cover_image' => 'a.png',
            'address' => 'x', 'phone' => '1',
        ]);
    }

    private function block(Store $p, string $type, array $extra = []): BioBlock
    {
        static $pos = 0;

        return BioBlock::create(array_merge([
            'store_id' => $p->id, 'type' => $type, 'label' => ucfirst($type).' block',
            'url' => 'https://example.test/'.$type, 'config' => [], 'position' => $pos++, 'active' => true,
        ], $extra));
    }

    public function test_block_set_renders_pro_gates_tipjar_and_hides_inactive(): void
    {
        config(['settings.admin_allow_bio_pro' => false]); // PRO dark (default)
        $svc = app(BioLinkService::class);
        $p = $this->store();

        // A spread of public block types + one PRO (tipjar) + one inactive + one
        // schedule-hidden (future window).
        foreach (['link', 'text', 'socials', 'whatsapp', 'hours', 'image', 'divider', 'faq'] as $t) {
            $this->block($p, $t);
        }
        $this->block($p, 'tipjar');                                   // PRO
        $this->block($p, 'link', ['active' => false, 'label' => 'Hidden inactive']);
        $this->block($p, 'link', ['visible_from' => now()->addDays(3), 'label' => 'Future']);

        $public = collect($svc->blocksFor($p->id))->pluck('type')->all();

        // 8 public types render; tipjar hidden (PRO off); inactive + future excluded.
        foreach (['link', 'text', 'socials', 'whatsapp', 'hours', 'image', 'divider', 'faq'] as $t) {
            $this->assertContains($t, $public, "public block $t should render");
        }
        $this->assertNotContains('tipjar', $public, 'tipjar is PRO — hidden without bio_pro');
        $labels = collect($svc->blocksFor($p->id))->pluck('label')->all();
        $this->assertNotContains('Hidden inactive', $labels, 'inactive block excluded');
        $this->assertNotContains('Future', $labels, 'schedule-hidden block excluded');

        // With the PRO entitlement (toggled test-locally), tipjar now renders.
        config(['settings.admin_allow_bio_pro' => true]);
        $withPro = collect($svc->blocksFor($p->id))->pluck('type')->all();
        $this->assertContains('tipjar', $withPro, 'tipjar renders WITH bio_pro — the gate is discriminating');
    }

    public function test_click_and_view_analytics_record(): void
    {
        $svc = app(BioLinkService::class);
        $p = $this->store();
        $b = $this->block($p, 'link');

        // Page view lands at block_id=0.
        $svc->recordView($p->id, null, 'direct', 'desktop');
        $this->assertSame(1, (int) DB::table('bio_clicks')->where('store_id', $p->id)->where('block_id', 0)->sum('count'));

        // Two clicks on the block bump bio_clicks.count and the denormalised cache.
        $svc->recordClick($p->id, $b->id, 'direct', 'desktop');
        $svc->recordClick($p->id, $b->id, 'direct', 'desktop');
        $this->assertSame(2, (int) DB::table('bio_clicks')->where('store_id', $p->id)->where('block_id', $b->id)->sum('count'));
        $this->assertSame(2, (int) $b->fresh()->clicks_cached, 'clicks_cached denormalises the click count');
    }
}
