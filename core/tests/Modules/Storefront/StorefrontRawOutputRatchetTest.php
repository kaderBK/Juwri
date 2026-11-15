<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Port of upstream 0525e8380 (A3 raw-output ratchet), for juwri's storefront
 * themes (views/store_templates; the bought theme (J9) is gone). The JSON-in-script
 * half is already enforced by JsonInScriptEscapingTest (WI-15-sec); this pins
 * the number of raw `{!! !!}` echoes (risk (c) in the Phase 0 exit security
 * notes) so it can only go down until P1-20 replaces them. New raw output must
 * be escaped, @json, or an HtmlString built from escaped parts.
 */
class StorefrontRawOutputRatchetTest extends TestCase
{
    // P3-13: 58 → 27 (the D11 premium themes: carely's 25 icons became an SVG sprite, glow's logo slot a partial,
    // aurelia's string @json, freshly's mode icons literal SVG; ember's hero stays, reviewed).
    private const MAX_RAW_ECHOES = 29; // p5r: +1 reviewed json_ld() (P6-305 kit JSON-LD partial); +1 DarkTokens <style> in marketplace-shell

    public function test_the_storefront_raw_output_count_never_grows(): void
    {
        $count = 0;
        foreach (File::allFiles(resource_path('views/store_templates')) as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php')) {
                $count += preg_match_all('/\{!!.*?!!\}/s', (string) file_get_contents($file->getPathname()));
            }
        }

        $this->assertLessThanOrEqual(self::MAX_RAW_ECHOES, $count, "Raw {!! !!} output in the storefront themes grew to {$count}. Escape it, use @json, or build an HtmlString from escaped parts.");
    }
}
