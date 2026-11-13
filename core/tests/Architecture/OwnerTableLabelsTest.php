<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Symfony\Component\Finder\Finder;

/**
 * P6-313: under 768px `.ui-table` hides the header row and prints each cell's data-label. An owner table cell
 * without one is a bare number on a phone, so every <td> in an owner `ui-table` carries data-label (or spans columns).
 */
it('gives every cell of an owner ui-table a data-label', function () {
    $missing = [];
    // Inline-input editor tables (not data tables), known gaps tracked in the post-launch backlog.
    $editors = ['menu/modifiers.blade.php', 'stores/edit.blade.php'];
    foreach ((new Finder)->files()->in(resource_path('views/owner'))->name('*.blade.php') as $file) {
        if (in_array(str_replace('\\', '/', $file->getRelativePathname()), $editors, true)) {
            continue;
        }
        $src = $file->getContents();
        if (! preg_match_all('#<table\b[^>]*\bui-table\b[^>]*>.*?</table>#s', $src, $tables)) {
            continue;
        }
        foreach ($tables[0] as $table) {
            if (preg_match_all('#<td\b[^>]*>#', $table, $tds)) {
                foreach ($tds[0] as $td) {
                    // data-primary is the row title; *actions* cells hold buttons only.
                    if (! preg_match('/data-label|colspan|data-primary|actions/', $td)) {
                        $missing[] = $file->getRelativePathname().': '.$td;
                    }
                }
            }
        }
    }

    expect(array_values(array_unique($missing)))->toBe([]);
});
