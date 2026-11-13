<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink;

use Juwri\Modules\Biolink\Services\BioLinkService;
use Tests\TestCase;

/**
 * BIOLINK-TPL T1-1 (A2): the 6 starter archetypes + the `starter` key on all 38
 * preset JSONs are DATA — this proves their integrity (valid, complete, and the
 * binding sections-vs-blocks rule holds) so applyStarter (A3) can trust them.
 *
 * Reads the JSON data files directly (a data-integrity check, not a blade render).
 */
class BioLinkStarterArchetypeTest extends TestCase
{
    private function startersDir(): string
    {
        return resource_path('views/bio/starters');
    }

    private function presetsDir(): string
    {
        return resource_path('views/bio/presets');
    }

    /** @return array<string,mixed> */
    private function archetype(string $key): array
    {
        $file = $this->startersDir().'/'.$key.'.json';
        $this->assertFileExists($file, "archetype $key must exist");
        $json = json_decode((string) file_get_contents($file), true);
        $this->assertIsArray($json, "archetype $key must be valid JSON");

        return $json;
    }

    public function test_all_six_archetypes_exist_and_are_well_formed(): void
    {
        foreach (BioLinkService::ARCHETYPES as $key) {
            $a = $this->archetype($key);
            $this->assertSame($key, $a['key'] ?? null);
            $this->assertIsArray($a['sections'] ?? null, "$key needs a sections map");
            $this->assertIsArray($a['blocks'] ?? null, "$key needs a blocks list");
            $this->assertNotEmpty($a['blocks'], "$key needs at least one block");
        }
    }

    public function test_no_archetype_seeds_a_section_typed_block(): void
    {
        // The binding rule: hours/map/whatsapp/socials are SECTIONS, never blocks.
        foreach (BioLinkService::ARCHETYPES as $key) {
            foreach ($this->archetype($key)['blocks'] as $block) {
                $type = $block['type'] ?? '';
                $this->assertNotContains(
                    $type,
                    BioLinkService::SECTION_BLOCK_TYPES,
                    "archetype $key must not seed the section-typed block '$type'"
                );
                $this->assertContains(
                    $type,
                    BioLinkService::SEEDABLE_BLOCK_TYPES,
                    "archetype $key seeds a non-seedable block type '$type'"
                );
            }
        }
    }

    public function test_archetype_sections_map_only_uses_valid_section_keys(): void
    {
        $valid = ['hours', 'socials', 'capture', 'map', 'whatsapp', 'reviews'];
        foreach (BioLinkService::ARCHETYPES as $key) {
            foreach (array_keys($this->archetype($key)['sections']) as $section) {
                $this->assertContains($section, $valid, "archetype $key has an unknown section '$section'");
            }
        }
    }

    public function test_archetype_block_entries_have_a_priority(): void
    {
        foreach (BioLinkService::ARCHETYPES as $key) {
            foreach ($this->archetype($key)['blocks'] as $block) {
                $this->assertArrayHasKey('priority', $block, "a $key block is missing priority");
                $this->assertIsNumeric($block['priority']);
            }
        }
    }

    public function test_all_38_presets_carry_a_starter_referencing_a_real_archetype(): void
    {
        $files = glob($this->presetsDir().'/*.json') ?: [];
        $this->assertCount(38, $files, 'exactly 38 preset JSONs are expected');

        foreach ($files as $file) {
            $json = json_decode((string) file_get_contents($file), true);
            $slug = $json['slug'] ?? basename($file);
            $starter = $json['starter'] ?? null;
            $this->assertNotNull($starter, "preset $slug is missing a starter key");
            $this->assertContains(
                $starter,
                BioLinkService::ARCHETYPES,
                "preset $slug references unknown archetype '$starter'"
            );
        }
    }
}
