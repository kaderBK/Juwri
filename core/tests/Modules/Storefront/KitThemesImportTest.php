<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use Juwri\Modules\Storefront\Services\Theme\KeptTemplates;
use Juwri\Modules\Storefront\Services\Theme\KitThemes;
use Tests\TestCase;

/**
 * P3-00: the five kit themes are imported as generated assets. theme.json and
 * css/tokens.css are never hand-edited — regenerating them from tools/themes
 * must reproduce the committed files byte for byte.
 */
class KitThemesImportTest extends TestCase
{
    private function assets(string $slug): string
    {
        return dirname(base_path()).'/assets/store_templates/'.$slug;
    }

    public function test_kit_slug_list_is_the_five_themes_and_does_not_overlap_the_kept_templates(): void
    {
        $this->assertSame(['table', 'brew', 'aisle', 'basket', 'book'], KitThemes::SLUGS);
        $this->assertSame([], array_intersect(KitThemes::SLUGS, KeptTemplates::withPageview()));
        $this->assertTrue(KitThemes::isKit('basket'));
        $this->assertFalse(KitThemes::isKit('crave'));
        $this->assertFalse(KitThemes::isKit(null));
    }

    public function test_every_kit_theme_ships_its_generated_assets(): void
    {
        foreach (KitThemes::SLUGS as $slug) {
            $dir = $this->assets($slug);
            foreach (['theme.json', 'cover.png', 'css/tokens.css'] as $file) {
                $this->assertFileExists("$dir/$file", "$slug/$file");
            }
            $json = json_decode((string) file_get_contents("$dir/theme.json"), true);
            $this->assertIsArray($json, "$slug theme.json parses");
            foreach ($json['presets'] ?? [] as $preset) {
                $thumb = $preset['thumbnail'] ?? $preset['thumb'] ?? null;
                if (is_string($thumb) && str_contains($thumb, 'store_templates/'.$slug.'/presets/')) {
                    $this->assertFileExists(dirname(base_path()).'/'.ltrim($thumb, '/'), "$slug preset thumbnail");
                }
            }
            $this->assertNotEmpty(glob("$dir/presets/*.png"), "$slug presets");
        }
    }

    public function test_generators_reproduce_the_committed_theme_files_byte_for_byte(): void
    {
        $python = trim((string) shell_exec('command -v python3'));
        if ($python === '') {
            $this->markTestSkipped('python3 is not installed on this machine.');
        }
        $src = dirname(base_path()).'/tools/themes';
        $tmp = sys_get_temp_dir().'/kit-regen-'.bin2hex(random_bytes(4));
        mkdir($tmp, 0777, true);
        try {
            shell_exec('cp -R '.escapeshellarg("$src/tools").' '.escapeshellarg("$src/tokens").' '.escapeshellarg($tmp).'/');
            shell_exec('cd '.escapeshellarg($tmp).' && '.escapeshellarg($python).' tools/build_theme_json.py >/dev/null 2>&1');
            shell_exec('cd '.escapeshellarg($tmp).' && '.escapeshellarg($python).' tools/build_theme_css.py >/dev/null 2>&1');
            foreach (KitThemes::SLUGS as $slug) {
                $this->assertFileEquals("$tmp/kit/$slug/theme.json", $this->assets($slug).'/theme.json', "$slug theme.json drifted from the generator");
                $this->assertFileEquals("$tmp/kit/$slug/css/tokens.css", $this->assets($slug).'/css/tokens.css', "$slug tokens.css drifted from the generator");
            }
        } finally {
            shell_exec('rm -rf '.escapeshellarg($tmp));
        }
    }
}
