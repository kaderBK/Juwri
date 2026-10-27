<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Models\Site;
use App\Models\SitePage;
use Juwri\Modules\Content\Services\SitePages\SiteBlockRegistry;
use Juwri\Modules\Content\Services\SitePages\SitePageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HOME-PAGES HP-24: schema-rot fence in test form. EVERY registered block type
 * must (a) have a wrapper view, (b) survive validateDocument round-tripping,
 * and (c) render from a minimal fixture document against the live layout
 * without throwing — one page containing the whole vocabulary. Prop renames or
 * partial moves that would silently break published pages on the 4 production
 * installs fail here first.
 */
class SiteBlockFixtureRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shareLayoutGlobals();
    }

    /** Minimal plausible fixture for one block type, driven by its def schema. */
    private function fixtureFor(string $type, array $def): array
    {
        $content = [];
        foreach ($def['content'] as $field) {
            $value = match ($field['type']) {
                'url' => 'https://example.com/x',
                'richtext' => '<p>Fixture body</p>',
                default => "Fixture {$field['name']}",
            };
            $content[$field['name']] = ! empty($field['localized']) ? ['en' => $value] : $value;
        }

        $settings = [];
        foreach ($def['settings'] as $field) {
            $settings[$field['name']] = (string) ($field['default'] ?? ($field['options'][0] ?? ''));
        }

        return [
            'id' => 'blk_'.substr(md5($type), 0, 6),
            'type' => $type,
            'enabled' => true,
            'version' => $def['version'],
            'settings' => $settings,
            'content' => $content,
        ];
    }

    public function test_every_registered_type_has_a_wrapper_view(): void
    {
        foreach (SiteBlockRegistry::types() as $type) {
            $this->assertTrue(
                view()->exists('site.blocks.'.$type),
                "wrapper view missing for block type '$type' (site/blocks/$type.blade.php)"
            );
        }
    }

    public function test_full_vocabulary_document_survives_validation_and_renders(): void
    {
        $blocks = [];
        foreach (SiteBlockRegistry::all() as $type => $def) {
            $blocks[] = $this->fixtureFor($type, $def);
        }

        // Round-trip through the save-path sanitizer: nothing may be dropped.
        $clean = SiteBlockRegistry::validateDocument(['blocks' => $blocks]);
        $this->assertCount(count($blocks), $clean['blocks'], 'validateDocument dropped a registered type fixture');
        foreach ($clean['blocks'] as $block) {
            $this->assertTrue(SiteBlockRegistry::renderable($block), "{$block['type']} not renderable after validation");
        }

        $site = Site::create(['key' => 'fixture-site', 'name' => 'Fixture', 'active' => true]);
        $page = SitePage::create([
            'site_id' => $site->id,
            'type' => 'home',
            'status' => 'published',
            'published_blocks' => $clean,
        ]);

        $html = app(SitePageRenderer::class)->render($page)->render();

        // The whole vocabulary rendered on one page without throwing; spot-check
        // one marker from each render class: kit-section-backed (hero), custom
        // markup (cta_banner fixture copy), purified richtext.
        $this->assertStringContainsString('data-site-block="hero"', $html);
        $this->assertStringContainsString('Fixture headline', $html);
        $this->assertStringContainsString('Fixture body', $html);
        $this->assertStringContainsString('og:site_name', $html);
    }

    public function test_stored_lower_version_still_renders_best_effort(): void
    {
        $site = Site::create(['key' => 'fixture-site2', 'name' => 'Fixture2', 'active' => true]);
        $page = SitePage::create([
            'site_id' => $site->id,
            'type' => 'home',
            'status' => 'published',
            'published_blocks' => ['version' => 1, 'blocks' => [
                // Simulates data published before a future def bump: version 0 < def 1.
                ['id' => 'blk_old111', 'type' => 'hero', 'enabled' => true, 'version' => 0, 'settings' => [], 'content' => []],
            ]],
        ]);

        $html = app(SitePageRenderer::class)->render($page)->render();

        $this->assertStringContainsString('data-site-block="hero"', $html);
    }
}
