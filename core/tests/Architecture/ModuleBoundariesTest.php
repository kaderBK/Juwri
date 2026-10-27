<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Architecture;

use Juwri\Core\Support\ModuleMap;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Module boundaries (P1-02, juwri-architecture.html "Module rules").
 *
 * Plain PHPUnit: no app boot. Reads the module map (app/Modules/dependencies.php plus the modules an installed edition
 * package contributes) and every PHP file under app/Core and the module folders, collects the class names each
 * file references (use statements, group use, inline qualified names) and checks:
 *
 *  1. dependencies.php itself: known layers, allowed edges point down (or are an
 *     explicit same-layer edge), no cycles.                       ENFORCED
 *  2. Community code (app/Core, app/Modules) never references an edition package's namespace.
 *                                                                  ENFORCED
 *  3. A module references only modules listed in its 'allowed'.   ENFORCED (P1-12 exit gate; covers Blade views and
 *     route files too, which the arch() tests cannot see). JUWRI_BOUNDARIES=report turns it back to a report.
 *  4. Cross-module references go to public parts (Contracts, Data, Events;
 *     Models read downward).                                       REPORT-ONLY
 *     (JUWRI_BOUNDARIES=enforce-all also fails on these.)
 *
 * Report-only findings (rule 4, or rule 3 under JUWRI_BOUNDARIES=report) are printed to STDERR and written to
 * storage/framework/testing/module-boundaries.txt; the test still passes.
 */
class ModuleBoundariesTest extends TestCase
{
    private const PUBLIC_PARTS = ['Contracts', 'Data', 'Events', 'Models'];

    /** @var array<string, array{layer:int, edition:string, path:string, namespace:string, allowed:list<string>}> */
    private static array $map;

    public static function setUpBeforeClass(): void
    {
        // P1-13 13i: Community's app/Modules/dependencies.php + the installed edition packages' modules.
        self::$map = ModuleMap::all();
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public function test_dependency_map_is_layered_and_acyclic(): void
    {
        $errors = [];
        $sameLayerAllowed = ['Auth' => ['Core'], 'Hr' => ['Team'], 'Pharmacy' => ['Verticals'], 'Laundry' => ['Verticals']];
        // P1-13 13i part 2b: an installed edition package adds its own documented same-layer edges (its test hook).
        foreach (\Tests\Support\EditionTestHooks::sameLayerEdges() as $module => $edges) {
            $sameLayerAllowed[$module] = array_merge($sameLayerAllowed[$module] ?? [], $edges);
        }
        $community = ModuleMap::community();
        foreach (glob(self::root().'/app/Modules/*', GLOB_ONLYDIR) ?: [] as $dir) {
            // (a folder with no PHP file in it is a leftover of a moved module, e.g. on a synced runner)
            if (! isset($community[basename($dir)]) && iterator_to_array($this->phpFiles($dir), false) !== []) {
                $errors[] = 'app/Modules/'.basename($dir).' is not in dependencies.php';
            }
        }

        foreach (self::$map as $module => $def) {
            if ($def['layer'] < 0 || $def['layer'] > 5) {
                $errors[] = "$module: layer {$def['layer']} outside 0..5";
            }
            if (! in_array($def['edition'], ['community', 'ee', 'split'], true)) {
                $errors[] = "$module: unknown edition {$def['edition']}";
            }
            // P1-13 13i: a Community module (app/Modules) has the Juwri\Modules\<M> namespace; a module of an installed
            // edition package has its own (its own boundary test checks that) but is never edition 'community'.
            if (isset($community[$module])) {
                $expectedNs = $module === 'Core' ? 'Juwri\\Core' : 'Juwri\\Modules\\'.$module;
                if ($def['namespace'] !== $expectedNs) {
                    $errors[] = "$module: namespace {$def['namespace']} (expected $expectedNs)";
                }
                if ($def['edition'] === 'ee') {
                    $errors[] = "$module: an Enterprise module lives in the edition package, not in the Community map";
                }
            } elseif ($def['edition'] === 'community') {
                $errors[] = "$module: a module of an edition package cannot be edition community";
            }
            if (! is_dir(ModuleMap::dir($def['path']))) {
                $errors[] = "$module: folder {$def['path']} missing";
            }
            foreach ($def['allowed'] as $dep) {
                if (! isset(self::$map[$dep])) {
                    $errors[] = "$module: allows unknown module $dep";

                    continue;
                }
                $down = self::$map[$dep]['layer'] < $def['layer'];
                $core = $dep === 'Core' && $module !== 'Core';
                $listed = in_array($dep, $sameLayerAllowed[$module] ?? [], true);
                if (! $down && ! $core && ! $listed) {
                    $errors[] = "$module (L{$def['layer']}) allows $dep (L".self::$map[$dep]['layer'].') — not downward';
                }
            }
        }

        array_push($errors, ...self::cycles(self::$map));

        $this->assertSame([], $errors, implode("\n", $errors));
    }

    /** P1-12 exit gate item 1: the cycle check itself finds cycles (a self-loop, a 2-cycle, a longer one) and passes a DAG. */
    public function test_cycle_check_finds_cycles(): void
    {
        $map = fn (array $edges): array => array_map(fn (array $allowed): array => ['allowed' => $allowed], $edges);

        $this->assertSame([], self::cycles($map(['Core' => [], 'A' => ['Core'], 'B' => ['A', 'Core'], 'C' => ['B', 'A']])));
        $this->assertSame(['cycle: A -> A'], self::cycles($map(['A' => ['A']])));
        $this->assertSame(['cycle: A -> B -> A'], self::cycles($map(['A' => ['B'], 'B' => ['A']])));
        $this->assertSame(['cycle: Core -> A -> B -> C -> A'], self::cycles($map(['Core' => ['A'], 'A' => ['B'], 'B' => ['C'], 'C' => ['A']])));
        $this->assertSame([], self::cycles(ModuleMap::all()), 'dependencies.php (+ installed edition modules) has no cycle');
    }

    /**
     * Every cycle the DFS meets in the allowed-edges graph ('cycle: A -> B -> A').
     *
     * @param  array<string, array{allowed: list<string>}>  $map
     * @return list<string>
     */
    private static function cycles(array $map): array
    {
        $errors = [];
        $state = [];
        $visit = function (string $m, array $stack) use (&$visit, &$state, &$errors, $map): void {
            if (($state[$m] ?? 0) === 2) {
                return;
            }
            if (($state[$m] ?? 0) === 1) {
                $errors[] = 'cycle: '.implode(' -> ', [...$stack, $m]);

                return;
            }
            $state[$m] = 1;
            foreach ($map[$m]['allowed'] ?? [] as $dep) {
                $visit($dep, [...$stack, $m]);
            }
            $state[$m] = 2;
        };
        foreach (array_keys($map) as $m) {
            $visit($m, []);
        }

        return $errors;
    }

    public function test_community_never_references_an_edition_package(): void
    {
        // The installed edition packages' root namespaces (none in the Community mirror): Community code never names one.
        $roots = array_map(fn (string $ns): string => rtrim($ns, '\\'), \Tests\Support\EditionFolders::namespaces());
        $violations = [];
        foreach ($this->communityFiles() as $file) {
            foreach (\Tests\Support\ReferencedNames::of($file) as $name) {
                foreach ($roots as $root) {
                    if ($name === $root || str_starts_with($name, $root.'\\')) {
                        $violations[] = $this->rel($file)." -> $name";
                    }
                }
            }
        }

        $this->assertSame([], $violations, "Community code must not import an edition package:\n".implode("\n", $violations));
    }

    public function test_modules_only_depend_on_allowed_modules(): void
    {
        $mode = (string) (getenv('JUWRI_BOUNDARIES') ?: 'enforce');
        $layer = [];
        $public = [];

        foreach ($this->moduleFiles() as [$file, $from]) {
            foreach (\Tests\Support\ReferencedNames::of($file) as $name) {
                [$to, $part] = $this->moduleOf($name);
                if ($to === null || $to === $from) {
                    continue;
                }
                if (! isset(self::$map[$to])) {
                    $layer[] = $this->rel($file)." -> $name (unknown module $to)";

                    continue;
                }
                if (! in_array($to, self::$map[$from]['allowed'], true)) {
                    $layer[] = $this->rel($file)." [$from L".self::$map[$from]['layer']."] -> $name [$to L".self::$map[$to]['layer'].']';
                } elseif ($to !== 'Core' && ! in_array($part, self::PUBLIC_PARTS, true)) {
                    $public[] = $this->rel($file)." [$from] -> $name (not a public part of $to)";
                }
            }
        }

        $this->report($layer, $public, $mode);

        if (in_array($mode, ['enforce', 'enforce-all'], true)) {
            $this->assertSame([], $layer, "Disallowed module dependencies:\n".implode("\n", $layer));
        }
        if ($mode === 'enforce-all') {
            $this->assertSame([], $public, "Non-public cross-module references:\n".implode("\n", $public));
        }
        $this->addToAssertionCount(1);
    }

    public function test_reference_parser_sees_imports_group_use_and_qualified_names(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'jmb').'.php';
        file_put_contents($file, <<<'PHP'
        <?php
        namespace Juwri\Modules\Menu\Services;

        use Juwri\Modules\Ordering\Models\Order;
        use Juwri\Modules\Payments\{Contracts\Gateway, Data\Charge as ChargeData};
        use Juwri\Modules\Sales\Plan, Juwri\Core\Money;

        class X {
            use SomeTrait;
            public function f() { return [\Juwri\Modules\Kitchen\Events\Fired::class, Sub\Thing::class, Money\Currency::class]; }
        }
        PHP);

        try {
            $names = \Tests\Support\ReferencedNames::of($file);
        } finally {
            @unlink($file);
        }

        foreach ([
            'Juwri\\Modules\\Ordering\\Models\\Order',
            'Juwri\\Modules\\Payments\\Contracts\\Gateway',
            'Juwri\\Modules\\Payments\\Data\\Charge',
            'Juwri\\Modules\\Sales\\Plan',
            'Juwri\\Core\\Money',
            'Juwri\\Modules\\Kitchen\\Events\\Fired',
            'Juwri\\Modules\\Menu\\Services\\Sub\\Thing',
            'Juwri\\Core\\Money\\Currency',
        ] as $expected) {
            $this->assertContains($expected, $names);
        }
        $this->assertNotContains('SomeTrait', $names);
        $this->assertSame(['Ordering', 'Models'], $this->moduleOf('Juwri\\Modules\\Ordering\\Models\\Order'));
        $this->assertSame(['Sales', 'Plan'], $this->moduleOf('Juwri\\Modules\\Sales\\Plan'));
    }

    /**
     * @param  list<string>  $layer
     * @param  list<string>  $public
     */
    private function report(array $layer, array $public, string $mode): void
    {
        $lines = [
            'Module boundaries ('.$mode.'): '.count($layer).' disallowed dependencies, '.count($public).' non-public references',
            ...array_map(fn ($l) => '  dependency: '.$l, $layer),
            ...array_map(fn ($l) => '  public:     '.$l, $public),
        ];
        $dir = self::root().'/storage/framework/testing';
        if (is_dir($dir) && is_writable($dir)) {
            @file_put_contents($dir.'/module-boundaries.txt', implode("\n", $lines)."\n");
        }
        // P1-18: Pest's parallel worker treats any STDERR output as a crashed worker, so print only on request
        // (JUWRI_BOUNDARIES_PRINT=1); the report file above is always written.
        if (($layer !== [] || $public !== []) && getenv('JUWRI_BOUNDARIES_PRINT')) {
            fwrite(STDERR, "\n".implode("\n", array_slice($lines, 0, 50)).(count($lines) > 50 ? "\n  …" : '')."\n");
        }
    }

    /**
     * @return array{0: ?string, 1: ?string} [module, first segment after the module namespace]
     */
    private function moduleOf(string $name): array
    {
        if (str_starts_with($name, 'Juwri\\Core\\') || $name === 'Juwri\\Core') {
            return ['Core', explode('\\', $name)[2] ?? null];
        }
        // Modules of an installed edition package (and its halves of split modules): their namespaces come from the map.
        foreach (ModuleMap::packageFolders() as $folder) {
            if (str_starts_with($name, $folder['namespace'].'\\')) {
                return [$folder['module'], explode('\\', substr($name, strlen($folder['namespace']) + 1))[0] ?: null];
            }
        }
        if (str_starts_with($name, 'Juwri\\Modules\\')) {
            $parts = explode('\\', substr($name, strlen('Juwri\\Modules\\')));

            return [$parts[0], $parts[1] ?? null];
        }

        return [null, null];
    }

    /** @return iterable<string> */
    private function communityFiles(): iterable
    {
        foreach (['app/Core', 'app/Modules'] as $dir) {
            yield from $this->phpFiles(self::root().'/'.$dir);
        }
    }

    /** @return iterable<array{0: string, 1: string}> */
    private function moduleFiles(): iterable
    {
        foreach ($this->phpFiles(self::root().'/app/Core') as $f) {
            yield [$f, 'Core'];
        }
        $base = self::root().'/app/Modules/';
        foreach ($this->phpFiles(self::root().'/app/Modules') as $f) {
            $module = explode('/', substr($f, strlen($base)))[0];
            if (str_ends_with($module, '.php')) {
                continue; // dependencies.php
            }
            yield [$f, $module];
        }
        // The module folders (and halves of split modules) of an installed edition package.
        foreach (ModuleMap::packageFolders() as $folder) {
            foreach ($this->phpFiles(ModuleMap::dir($folder['path'])) as $f) {
                yield [$f, $folder['module']];
            }
        }
    }

    /** @return iterable<string> */
    private function phpFiles(string $dir): iterable
    {
        if (! is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
                yield $f->getPathname();
            }
        }
    }

    private function rel(string $file): string
    {
        return substr($file, strlen(self::root()) + 1);
    }
}
