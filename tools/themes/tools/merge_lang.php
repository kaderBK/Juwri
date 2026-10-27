<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD
/**
 * Add-only merge of the kit storefront strings (tools/themes/kit/lang/<locale>.json)
 * into core/lang/<locale>/lang.php. Existing core values are never overwritten;
 * keys that already exist in core with a different value are reported, not touched.
 *
 * Run from the repo root: php tools/themes/tools/merge_lang.php [--dry]
 * Same file format as lang_register_missing_key() (var_export, append order).
 */
$root = dirname(__DIR__, 3);
$dry = in_array('--dry', $argv, true);
$added = 0; $collisions = [];
$collide = json_decode(file_get_contents("$root/tools/themes/kit/lang/collisions.json"), true, 512, JSON_THROW_ON_ERROR);
foreach (['en', 'ar', 'de', 'es', 'fr', 'hi', 'pt', 'ru', 'tr', 'ur', 'zh'] as $l) {
    $file = "$root/core/lang/$l/lang.php";
    $lines = include $file;
    $kit = json_decode(file_get_contents("$root/tools/themes/kit/lang/$l.json"), true, 512, JSON_THROW_ON_ERROR);
    $n = 0;
    foreach ($kit as $k => $v) {
        if (array_key_exists($k, $lines)) {
            if ($lines[$k] !== $v) {
                $collisions[$l][$k] = [$lines[$k], $v];
            }
            continue;
        }
        $lines[$k] = $v;
        $n++;
    }
    // Kit strings whose slug collides with a core key of a DIFFERENT meaning live under their own key
    // (tools/themes/kit/lang/collisions.json: kit_min, kit_per_unit, ...); the themes call ___('kit_min').
    foreach ($collide as $c) {
        if (! array_key_exists($c['key'], $lines)) {
            $lines[$c['key']] = $c['values'][$l];
            $n++;
        }
    }
    $added += $n;
    echo "$l: +$n\n";
    if (! $dry && $n > 0) {
        file_put_contents($file, "<?php\n\nreturn ".var_export($lines, true).";\n");
    }
}
echo "total added: $added\n";
foreach ($collisions as $l => $c) {
    echo "$l collisions with a different value: ".count($c)."\n";
}
