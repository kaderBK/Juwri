<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/**
 * rev/multi-vertical showcase catalog loader.
 *
 * Each vertical's catalog now lives in its own file under
 * data/vertical_showcase/<type>.php (so they can be authored independently).
 * A vertical with no sub-file yet falls back to the frozen legacy catalog, so
 * the seeder never produces an empty store mid-migration.
 *
 * Each sub-file returns: ['brands' => [..names..], 'categories' => [
 *   ['name' => 'Category', 'items' => [ [ 'name','price','brand','desc',
 *     'image' => '<file in ../showcase_images/<type>/>', 'popular','stock',
 *     'variants' => ['Size'=>[...],'Color'=>[...]], 'variant_price'=>[val=>price],
 *     'attrs'=>[...], 'oem'=>[...], 'xref'=>[...], 'fits'=>[[make,model,submodel,engine,from,to]],
 *     'weight','dimensions','pricing_unit','turnaround_hours','age_restricted',
 *     'batch_no','expiry_date' ], ... ] ], ... ] ]
 */
$legacy = include __DIR__.'/vertical_showcase_legacy.php';

$out = [];
foreach (['retail', 'cosmetic', 'clothing', 'carparts', 'laundry', 'pharmacy', 'hotel'] as $type) {
    $file = __DIR__.'/vertical_showcase/'.$type.'.php';
    $out[$type] = is_file($file) ? include $file : ($legacy[$type] ?? ['categories' => []]);
}

return $out;
