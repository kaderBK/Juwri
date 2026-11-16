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
 *
 * Covers the value forms Alpine would blank: value="1", :value="1" and value="{{ 1 }}"
 * (single or double quoted). Array-valued checkboxes (value="media", value="{{ $id }}")
 * are legitimate x-model usage and are not flagged.
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
            preg_match_all('/<input\b[^>]*>/', (string) file_get_contents($file->getPathname()), $m);
            foreach ($m[0] as $tag) {
                $isCheckbox = preg_match('/\btype\s*=\s*(["\'])checkbox\1/', $tag) === 1;
                $hasValueOne = preg_match('/\b:?value\s*=\s*(["\'])\s*(?:1|\{\{\s*1\s*\}\})\s*\1/', $tag) === 1;
                if ($isCheckbox && $hasValueOne && str_contains($tag, 'x-model')) {
                    $bad[] = str_replace(resource_path(), '', $file->getPathname()).': '.preg_replace('/\s+/', ' ', $tag);
                }
            }
        }

        $this->assertSame([], $bad, 'bind value="1" checkboxes with :checked + @change, not x-model');
    }
}
