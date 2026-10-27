<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Models\Site;
use Juwri\Modules\Content\Services\SitePages\LegacyHomeBridge;
use Juwri\Modules\Content\Services\SitePages\SiteBlockRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;
use Tests\TestCase;

/**
 * HOME-PAGES HP-30: bridge composes a draft reproducing today's section order;
 * idempotent; refuses to clobber hand-edited drafts; never publishes; seeds
 * default-locale SEO verbatim from Options.
 */
class LegacyHomeBridgeTest extends TestCase
{
    use RefreshDatabase;

    private function site(): Site
    {
        return Site::create(['key' => 'bridge-'.uniqid(), 'name' => 'B', 'active' => true]);
    }

    public function test_seed_creates_draft_in_legacy_order_and_never_publishes(): void
    {
        config(['settings.site_title' => 'عنوان عربي حرفي', 'settings.meta_description' => 'وصف عربي حرفي']);

        $result = app(LegacyHomeBridge::class)->seed($this->site());

        $this->assertSame('created', $result['action']);
        $page = $result['page'];
        $this->assertSame('draft', $page->status);
        $this->assertNull($page->published_blocks);
        $this->assertSame(LegacyHomeBridge::LEGACY_ORDER, array_column($page->blocks['blocks'], 'type'));

        // Every bridged type is registered and renderable.
        foreach ($page->blocks['blocks'] as $block) {
            $this->assertTrue(SiteBlockRegistry::renderable($block), $block['type']);
            $this->assertSame([], $block['content'], 'bridge leaves content empty (___() defaults)');
        }

        // Default locale (phpunit: en... whichever) gets Options VERBATIM.
        $default = LaravelLocalization::getDefaultLocale();
        $this->assertSame('عنوان عربي حرفي', $page->seo['meta_title'][$default]);
        $this->assertSame('وصف عربي حرفي', $page->seo['meta_description'][$default]);
    }

    public function test_seed_is_idempotent_and_refuses_hand_edits_without_force(): void
    {
        $bridge = app(LegacyHomeBridge::class);
        $site = $this->site();

        $first = $bridge->seed($site);
        $this->assertSame('created', $first['action']);

        $this->assertSame('skipped', $bridge->seed($site)['action']);

        // Hand-edit the draft → refuse.
        $page = $first['page'];
        $doc = $page->blocks;
        $doc['blocks'][0]['content'] = ['headline' => ['en' => 'edited']];
        $page->update(['blocks' => $doc]);
        $this->assertSame('refused', $bridge->seed($site)['action']);

        // --force overwrites; existing seo preserved.
        $forced = $bridge->seed($site, true);
        $this->assertSame('refreshed', $forced['action']);
        $this->assertSame([], $forced['page']->fresh()->blocks['blocks'][0]['content']);
    }
}
