<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * P0-2: no storefront theme may echo a user/DB text field raw. Guards against
 * a new theme (or a merge) reintroducing an unsanitized `{!! $store->... !!}`.
 */
class PostTemplateSanitizationTest extends TestCase
{
    private function bladeFiles(): array
    {
        $dir = resource_path('views/store_templates');
        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        $out = [];
        foreach ($rii as $f) {
            if (str_ends_with($f->getFilename(), '.blade.php')) {
                $out[] = $f->getPathname();
            }
        }

        return $out;
    }

    public function test_no_raw_description_echoes_in_store_templates(): void
    {
        // These text fields are user/DB authored → must go through @clean, never {!! !!}.
        $banned = [
            '{!! $store->description',
            '{!! $store->bio_description',
            '{!! strip_tags($menu->description',
            '{!! $menu->description',
        ];

        $offenders = [];
        foreach ($this->bladeFiles() as $file) {
            $c = file_get_contents($file);
            foreach ($banned as $needle) {
                if (str_contains($c, $needle)) {
                    $offenders[] = str_replace(resource_path('views/store_templates/'), '', $file).' :: '.$needle;
                }
            }
        }

        $this->assertSame([], $offenders, "Raw unsanitized echoes found:\n".implode("\n", $offenders));
    }

    public function test_clean_renders_description_inert(): void
    {
        $payload = '<b>bold</b><span onclick="steal()">x</span><script>alert(1)</script>';
        $out = Blade::render('@clean($v, "richtext")', ['v' => $payload]);

        $this->assertStringContainsString('<b>bold</b>', $out);
        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('steal()', $out);
    }
}
