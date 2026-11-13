<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Juwri\Modules\Content\Services\SitePages\SiteBlockRegistry;
use PHPUnit\Framework\TestCase;

/**
 * HOME-PAGES HP-17: pins the SiteBlockRegistry validation contract — unknown
 * types dropped, unknown fields stripped, max-per-type enforced in document
 * order, def version stamped, and the renderable() future-version fence.
 * Every def must also satisfy the structural invariants the builder forms and
 * renderer rely on.
 */
class SiteBlockRegistryTest extends TestCase
{
    public function test_validate_document_drops_junk_and_enforces_max(): void
    {
        $doc = ['blocks' => [
            ['type' => 'hero', 'content' => ['headline' => ['en' => 'Hi'], 'junk' => 'x'], 'settings' => ['evil' => 'y']],
            ['type' => 'hero', 'content' => ['headline' => ['en' => 'second hero — max 1']]],
            ['type' => 'unknown_type', 'content' => []],
            'garbage-string',
            42,
        ]];

        $clean = SiteBlockRegistry::validateDocument($doc);

        $this->assertSame(1, $clean['version']);
        $this->assertCount(1, $clean['blocks']);

        $block = $clean['blocks'][0];
        $this->assertSame('hero', $block['type']);
        $this->assertArrayNotHasKey('junk', $block['content']);
        $this->assertSame([], $block['settings']);
        $this->assertSame(['en' => 'Hi'], $block['content']['headline']);
    }

    public function test_validate_stamps_def_version_and_generates_safe_ids(): void
    {
        $block = SiteBlockRegistry::validate(['type' => 'hero', 'id' => '<script>alert(1)</script>']);

        $this->assertSame(1, $block['version']);
        $this->assertMatchesRegularExpression('/^blk_[a-f0-9]{4,8}$/', $block['id']);

        // A well-formed id is preserved (stable block identity across saves).
        $kept = SiteBlockRegistry::validate(['type' => 'hero', 'id' => 'blk_ab12cd']);
        $this->assertSame('blk_ab12cd', $kept['id']);
    }

    public function test_renderable_fences_unknown_types_and_future_versions(): void
    {
        $this->assertTrue(SiteBlockRegistry::renderable(['type' => 'hero', 'version' => 1]));
        $this->assertTrue(SiteBlockRegistry::renderable(['type' => 'hero'])); // missing version = v1
        $this->assertFalse(SiteBlockRegistry::renderable(['type' => 'hero', 'version' => 99]));
        $this->assertFalse(SiteBlockRegistry::renderable(['type' => 'nope']));
        $this->assertFalse(SiteBlockRegistry::renderable([]));
    }

    public function test_empty_or_malformed_documents_normalize_to_empty(): void
    {
        $this->assertSame(['version' => 1, 'blocks' => []], SiteBlockRegistry::validateDocument(null));
        $this->assertSame(['version' => 1, 'blocks' => []], SiteBlockRegistry::validateDocument('junk'));
        $this->assertSame(['version' => 1, 'blocks' => []], SiteBlockRegistry::validateDocument([]));
    }

    public function test_every_def_satisfies_structural_invariants(): void
    {
        foreach (SiteBlockRegistry::all() as $key => $def) {
            $this->assertSame($key, $def['key'], "def key mirrors array key: $key");
            $this->assertIsString($def['label']);
            $this->assertIsInt($def['version'], "$key carries an int version");
            $this->assertGreaterThanOrEqual(1, $def['version']);
            $this->assertIsArray($def['settings']);
            $this->assertIsArray($def['content']);

            foreach ($def['settings'] as $field) {
                $this->assertArrayHasKey('name', $field, "$key setting has name");
                // Colors come from sites.theme tokens (HP-50) — never block settings.
                $this->assertStringNotContainsString('color', $field['name'], "$key setting must not carry colors");
            }
            foreach ($def['content'] as $field) {
                $this->assertArrayHasKey('name', $field);
                $this->assertArrayHasKey('type', $field);
                $this->assertArrayHasKey('localized', $field, "$key content field declares localized");
                $this->assertArrayHasKey('maxlen', $field, "$key content field declares maxlen");
            }
        }
    }
}
