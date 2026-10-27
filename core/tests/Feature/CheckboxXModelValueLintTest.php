<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Owner report: ticking "Active" on a branch never saved. Alpine's x-model on a checkbox
 * wipes its value="1" to "" when the bound model starts undefined (edit: {}), so a ticked
 * box posted an empty value and $request->boolean() read false. Owner views bind such
 * checkboxes with :checked="expr" @change="expr = $event.target.checked" instead.
 */
class CheckboxXModelValueLintTest extends TestCase
{
    public function test_no_value_one_checkbox_uses_x_model(): void
    {
        $bad = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views/owner'), RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            preg_match_all('/<input[^>]*>/', (string) file_get_contents($file->getPathname()), $m);
            foreach ($m[0] as $tag) {
                if (str_contains($tag, 'type="checkbox"') && str_contains($tag, 'value="1"') && str_contains($tag, 'x-model=')) {
                    $bad[] = str_replace(resource_path(), '', $file->getPathname()).': '.preg_replace('/\s+/', ' ', $tag);
                }
            }
        }

        $this->assertSame([], $bad, 'bind value="1" checkboxes with :checked + @change, not x-model');
    }
}
