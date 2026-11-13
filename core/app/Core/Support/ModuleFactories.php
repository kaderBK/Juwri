<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

/**
 * P1-11: factories live in app/Modules/<M>/Database/Factories (Core: app/Core/Database/Factories). A model finds its
 * factory by the module convention (`…\Models\X` → `…\Database\Factories\XFactory`) or, for a model that still sits
 * in App\Models, by its short name across all modules.
 */
final class ModuleFactories
{
    /** @var array<string, class-string>|null short model name => factory class */
    private static ?array $byShortName = null;

    /** @return class-string|null */
    public static function forModel(string $model): ?string
    {
        if (preg_match('/^(Juwri\\\\(?:Core|(?:\w+\\\\)?Modules\\\\\w+))\\\\Models\\\\(.+)$/', $model, $m)) {
            $candidate = $m[1].'\\Database\\Factories\\'.$m[2].'Factory';
            if (class_exists($candidate)) {
                return $candidate;
            }
        }

        return self::index()[class_basename($model)] ?? null;
    }

    /** @return array<string, class-string> */
    private static function index(): array
    {
        if (self::$byShortName === null) {
            self::$byShortName = [];
            $dirs = [app_path('Core/Database/Factories') => 'Juwri\\Core\\Database\\Factories\\'];
            foreach (glob(app_path('Modules/*/Database/Factories'), GLOB_ONLYDIR) ?: [] as $dir) {
                $dirs[$dir] = 'Juwri\\Modules\\'.basename(dirname($dir, 2)).'\\Database\\Factories\\';
            }
            // P1-13 13c/13i: module folders of the installed edition packages (their modules and split-module halves).
            foreach (ModuleMap::packageFolders() as $folder) {
                $dir = ModuleMap::dir($folder['path']).'/Database/Factories';
                if (is_dir($dir)) {
                    $dirs[$dir] = $folder['namespace'].'\\Database\\Factories\\';
                }
            }
            foreach ($dirs as $dir => $ns) {
                foreach (glob($dir.'/*Factory.php') ?: [] as $file) {
                    $name = basename($file, 'Factory.php');
                    self::$byShortName[$name] ??= $ns.$name.'Factory';
                }
            }
        }

        return self::$byShortName;
    }
}
