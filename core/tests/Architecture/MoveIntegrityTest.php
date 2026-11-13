<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Architecture;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use App\Http\Controllers\DeveloperDocsController as ContentDocs;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\TestCase;

/**
 * Regression tests for the bug classes P1-08f found after moving ~1500 files (a class move rewrites names,
 * but not paths built at run time, strings resolved against a route group namespace, or helper files).
 *
 *  - every route action names a class and a method that exist (15 actions had been pointed at the wrong
 *    controller by short name, and plain 'Juwri\…@m' strings lost their leading backslash, so the group
 *    namespace was prepended: "App\Http\Controllers\Juwri\…" does not exist);
 *  - every require / include with a literal path points at an existing file (Helper.php → PluginHelper.php);
 *  - the Liquid drop reference finds the drop classes (it globbed the old folder: empty reference);
 *  - node scripts started by PHP exist next to the class that starts them (headless-render.mjs, browser-check.mjs).
 */
class MoveIntegrityTest extends TestCase
{
    public function test_every_route_action_resolves_to_an_existing_class_and_method(): void
    {
        $bad = [];
        $checked = 0;
        /** @var Route $route */
        foreach (RouteFacade::getRoutes() as $route) {
            $action = $route->getAction('uses');
            if (! is_string($action)) {
                continue; // closure, or [Class, 'method'] arrays are resolved to a string by the router
            }
            $checked++;
            [$class, $method] = array_pad(explode('@', $action, 2), 2, '__invoke');
            $class = ltrim($class, '\\');
            $where = $route->methods()[0].' /'.ltrim($route->uri(), '/')." -> $action";
            if (! class_exists($class)) {
                $bad[] = "$where: class $class does not exist";

                continue;
            }
            if (! (new ReflectionClass($class))->hasMethod($method)) {
                $bad[] = "$where: $class has no method $method";
            }
        }

        $this->assertGreaterThan(1500, $checked, 'route discovery found too few controller routes');
        $this->assertSame([], $bad, "routes whose action does not resolve:\n".implode("\n", $bad));
    }

    public function test_route_actions_never_start_with_the_group_namespace_prefix_of_a_module_class(): void
    {
        $bad = [];
        foreach (RouteFacade::getRoutes() as $route) {
            $action = $route->getAction('uses');
            if (is_string($action) && preg_match('/^(?:\\\\)?App\\\\Http\\\\Controllers\\\\(Juwri|Tests)\\\\/', $action)) {
                $bad[] = $action;
            }
        }
        $this->assertSame([], array_values(array_unique($bad)), 'a Juwri\\… string action lost its leading backslash');
    }

    public function test_every_literal_require_and_include_path_exists(): void
    {
        $bad = [];
        $seen = 0;
        foreach ([app_path(), base_path('bootstrap')] as $root) {
            foreach ($this->phpFiles($root) as $file) {
                $src = (string) file_get_contents($file);
                if (! preg_match_all('/\b(?:require|require_once|include|include_once)\b\s*\(?\s*(__DIR__|base_path\(|app_path\()\s*\.?\s*[\'"]([^\'"]+)[\'"]/', $src, $m, PREG_SET_ORDER)) {
                    continue;
                }
                foreach ($m as $hit) {
                    $seen++;
                    $path = match (true) {
                        str_starts_with($hit[1], '__DIR__') => dirname($file).$hit[2],
                        str_starts_with($hit[1], 'base_path') => base_path($hit[2]),
                        default => app_path($hit[2]),
                    };
                    if (! is_file($path)) {
                        $bad[] = str_replace(base_path().'/', '', $file).": {$hit[2]}";
                    }
                }
            }
        }
        $this->assertGreaterThan(0, $seen);
        $this->assertSame([], $bad, "require/include of a file that does not exist:\n".implode("\n", $bad));

        // composer's autoload "files" are required on every request
        $composer = json_decode((string) file_get_contents(base_path('composer.json')), true);
        foreach ($composer['autoload']['files'] ?? [] as $rel) {
            $this->assertFileExists(base_path($rel), "composer autoload file $rel");
        }
    }

    public function test_every_module_route_file_is_called_by_a_skeleton_slot_and_every_slot_is_registered(): void
    {
        $called = [];
        // P1-13 13f: an edition package's module route block that needs its own group (the reseller portal: an ee
        // middleware class) lives in one of its slot files and calls its other slots from there — nested slots count as
        // called too. (Package module folders come from the module map; none in the Community mirror.)
        $moduleRouteFiles = array_merge(glob(base_path('app/Modules/*/Routes/*.php')) ?: [], \Tests\Support\EditionFolders::glob('Routes/*.php'));
        foreach ([...$this->phpFiles(base_path('routes')), ...$moduleRouteFiles] as $file) {
            // code only: a module route file's header comment may name its own slot
            $code = implode('', array_map(fn ($t) => is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $t[1]) : $t, token_get_all((string) file_get_contents($file))));
            if (preg_match_all("/ModuleRoutes::slot\\('([^']+)'\\)/", $code, $m)) {
                array_push($called, ...$m[1]);
            }
        }
        // A skeleton slot of a module that is not in the module map (an edition package that is not installed) is skipped
        // at run time (ModuleRoutes::slot), so it needs no provider here.
        // (Also a slot whose route file only an edition package ships — the package half of a split module.)
        $routeFiles = array_map(fn (string $f): string => basename(dirname($f, 2)).'/'.basename($f, '.php'), [...$moduleRouteFiles, ...(glob(base_path('app/Core/Routes/*.php')) ?: [])]);
        $called = array_values(array_filter($called, fn (string $slot): bool => isset(\Juwri\Core\Support\ModuleMap::all()[explode('/', $slot)[0]])
            && in_array($slot, $routeFiles, true)));
        $registered = \Juwri\Core\Support\ModuleRoutes::slots();
        $this->assertGreaterThan(100, count($called));
        $this->assertSame([], array_values(array_diff($called, $registered)), 'slot called in routes/ but no module provider registers it');
        $this->assertSame([], array_values(array_diff($registered, $called)), 'module route file that no skeleton slot includes (its routes would be lost)');
        $this->assertSame(count($called), count(array_unique($called)), 'a slot is included twice (duplicate routes)');
    }

    public function test_liquid_drop_reference_finds_the_drops(): void
    {
        $drops = ContentDocs::dropReference();
        $this->assertNotEmpty($drops, 'dropReference() found no drop classes (wrong folder or namespace)');
        $this->assertArrayHasKey('CustomerDrop', $drops);
    }

    public function test_node_scripts_referenced_from_php_exist_next_to_the_referencing_file(): void
    {
        $bad = [];
        $seen = 0;
        foreach ($this->phpFiles(app_path()) as $file) {
            $src = (string) file_get_contents($file);
            if (! preg_match_all('/__DIR__\s*\.\s*[\'"](\/[^\'"]+\.(?:mjs|cjs|js))[\'"]/', $src, $m)) {
                continue;
            }
            foreach ($m[1] as $rel) {
                $seen++;
                if (! is_file(dirname($file).$rel)) {
                    $bad[] = str_replace(base_path().'/', '', $file).": $rel";
                }
            }
        }
        $this->assertGreaterThanOrEqual(2, $seen, 'HeadlessChrome and BrowserGate each start a node script');
        $this->assertSame([], $bad, "node script missing:\n".implode("\n", $bad));
    }

    /** @return iterable<string> */
    private function phpFiles(string $root): iterable
    {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.php') && ! str_ends_with($f->getFilename(), '.blade.php')) {
                yield $f->getPathname();
            }
        }
    }
}
