<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Juwri\Core\Support\ModuleMap;

/*
 * Module boundaries as Pest arch() tests (P1-18), mirroring tests/Architecture/ModuleBoundariesTest.php (kept until the
 * coordinator decides). Same source of truth: app/Modules/dependencies.php.
 *
 *  1. A module uses only the modules its 'allowed' list names (Core uses none).              0 violations today
 *  2. A module reaches other modules only through their public parts (Contracts, Data,
 *     Events, Models). The non-public references that exist today are the baseline in
 *     module-boundary-baseline.php: each listed class may use exactly its listed targets,
 *     everything else is held to the rule. The baseline may shrink, never grow.
 *  3. Community code (Juwri\Core, Juwri\Modules, App) never uses an edition package's namespace.
 * P1-13 13i part 2b: nothing here names an edition package: namespaces and folders come from the module map, the baseline of
 * an installed package from its test hook, and the package's own boundary rules live in its tests.
 */

// P1-13 13i: Community's app/Modules/dependencies.php + the installed edition packages' modules.
$map = ModuleMap::all();
$baseline = require __DIR__.'/module-boundary-baseline.php';
foreach (\Tests\Support\EditionTestHooks::boundaryBaseline() as $class => $targets) {
    $baseline[$class] = array_values(array_unique(array_merge($baseline[$class] ?? [], $targets)));
}

// Every namespace the map knows (Core, Community modules, a package's modules and halves of split modules) => its folder.
$roots = ['Juwri\\Core' => ['Core', ModuleMap::dir('app/Core')]];
foreach ($map as $module => $def) {
    if ($module !== 'Core') {
        $roots[$def['namespace']] = [$module, ModuleMap::dir($def['path'])];
    }
}
foreach (ModuleMap::packageFolders() as $folder) {
    $roots[$folder['namespace']] = [$folder['module'], ModuleMap::dir($folder['path'])];
}
uksort($roots, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

$publicParts = ['Contracts', 'Data', 'Events', 'Models'];
$coreDir = realpath(__DIR__.'/../..');

// P1-13 13c: a module's namespace and folder come from the map.
$namespaceOf = static fn (string $module): string => $map[$module]['namespace'];

/** Namespaces of a module that are NOT public parts: every sub-folder except the public ones, plus root-level classes. */
$nonPublicOf = static function (string $module) use ($map, $coreDir, $publicParts): array {
    $out = [];
    $dir = ModuleMap::dir($map[$module]['path']);
    $ns = $map[$module]['namespace'];
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir.'/'.$entry;
        if (is_dir($path) && ! in_array($entry, $publicParts, true)) {
            $out[] = $ns.'\\'.$entry.'\\';
        } elseif (is_file($path) && str_ends_with($entry, '.php')) {
            $out[] = $ns.'\\'.substr($entry, 0, -4);
        }
    }

    return $out;
};

$moduleNames = array_keys($map);

// 1. layer rule ------------------------------------------------------------------------------------------------------
foreach ($map as $module => $def) {
    $forbidden = [];
    foreach ($moduleNames as $other) {
        if ($other === $module || in_array($other, $def['allowed'], true)) {
            continue;
        }
        if ($other === 'Core') {
            $forbidden[] = 'Juwri\\Core\\';

            continue;
        }
        $forbidden[] = $map[$other]['namespace'].'\\';
        foreach (ModuleMap::parts()[$other] ?? [] as $part) {
            $forbidden[] = $part['namespace'].'\\';
        }
    }

    arch("module {$module} uses only the modules it allows")
        ->expect($namespaceOf($module))
        ->not->toUse($forbidden);
}

// 2. public-parts rule (with the baseline) ----------------------------------------------------------------------------
// P1-13 13i: a class's module = the map namespace it lives under (Juwri\\Core\\X → Core).
$moduleOfClass = static function (string $class) use ($roots): string {
    foreach ($roots as $namespace => [$module]) {
        if (str_starts_with($class, $namespace.'\\')) {
            return $module;
        }
    }

    return explode('\\', $class)[2];
};

$usersByModule = [];
foreach (array_keys($baseline) as $class) {
    $usersByModule[$moduleOfClass($class)][] = $class;
}

foreach ($map as $module => $def) {
    $others = [];
    foreach ($moduleNames as $other) {
        if ($other !== $module && $other !== 'Core') {
            array_push($others, ...$nonPublicOf($other));
        }
    }

    arch("module {$module} reaches other modules only through their public parts")
        ->expect($namespaceOf($module))
        ->not->toUse($others)
        ->ignoring($usersByModule[$module] ?? []);
}

foreach ($baseline as $class => $targets) {
    $own = $moduleOfClass($class);
    $others = [];
    foreach ($moduleNames as $other) {
        if ($other !== 'Core' && $other !== $own) {
            array_push($others, ...$nonPublicOf($other));
        }
    }

    arch("baseline: {$class} uses only its listed non-public references")
        ->expect($class)
        ->not->toUse($others)
        ->ignoring($targets);
}

// 3. community never uses an edition package -------------------------------------------------------------------------
// P1-13 13i: the installed packages' root namespaces (none in the Community mirror, where there is nothing to forbid).
$packageRoots = array_map(fn (string $ns): string => rtrim($ns, '\\'), \Tests\Support\EditionFolders::namespaces());
if ($packageRoots !== []) {
    arch('community code never uses an edition package')
        ->expect(['Juwri\\Core', 'Juwri\\Modules', 'App'])
        ->not->toUse($packageRoots);
}

// the baseline stays honest: every listed class and target still exists
test('module boundary baseline lists only existing classes', function () use ($baseline, $roots) {
    $file = static function (string $class) use ($roots): string {
        foreach ($roots as $namespace => [, $dir]) {
            if (str_starts_with($class, $namespace.'\\')) {
                return $dir.'/'.str_replace('\\', '/', substr($class, strlen($namespace) + 1)).'.php';
            }
        }

        return dirname(__DIR__, 2).'/'.str_replace('\\', '/', $class).'.php';
    };
    $missing = [];
    foreach ($baseline as $class => $targets) {
        foreach ([$class, ...$targets] as $c) {
            if (! is_file($file($c))) {
                $missing[] = $c;
            }
        }
    }
    expect($missing)->toBe([]);
});
