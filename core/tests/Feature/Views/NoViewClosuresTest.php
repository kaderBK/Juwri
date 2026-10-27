<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\Views;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Juwri\Core\Support\PriceFormatter;
use Juwri\Core\Support\ViewHelpers;
use Tests\TestCase;

/**
 * P6-36/P6-36b: on PHP 8.4 every include of a compiled view that holds a closure literal (`fn (...)`, `function (...)`) keeps
 * about 250 bytes in a long-lived Octane worker, whether or not the closure runs. Views that render on every request must
 * use helper classes (Juwri\Core\Support\ViewHelpers) instead. The scan compiles each view and looks for PHP closure tokens,
 * so JavaScript `function (` inside <script> does not count.
 */
class NoViewClosuresTest extends TestCase
{
    /** Views rendered on (nearly) every dashboard or storefront request, besides everything under components/. */
    private const HOT = [
        'owner/orders/order-row.blade.php',
        'owner/orders/index.blade.php',
        'owner/dashboard/index.blade.php',
        'owner/dashboard/partials/onboarding-checklist.blade.php',
        'owner/dashboard/partials/external-redirect-banner.blade.php',
        'owner/partials/flash.blade.php',
        'store_templates/crave/index.blade.php',
        'store_templates/crave/profile.blade.php',
        'store_templates/crave/track.blade.php',
        'store_templates/crave/account.blade.php',
    ];

    /** @return array<int, string> relative paths */
    private function hotViews(): array
    {
        $views = self::HOT;
        foreach (File::allFiles(resource_path('views/components')) as $f) {
            if (str_ends_with($f->getFilename(), '.blade.php')) {
                $views[] = 'components/'.$f->getRelativePathname();
            }
        }

        return $views;
    }

    public function test_hot_views_hold_no_php_closure_literals(): void
    {
        $compiler = app('blade.compiler');
        $offenders = [];
        foreach ($this->hotViews() as $rel) {
            $path = resource_path('views/'.$rel);
            $this->assertFileExists($path);
            foreach (token_get_all($compiler->compileString((string) file_get_contents($path))) as $t) {
                if (is_array($t) && in_array($t[0], [T_FUNCTION, T_FN], true)) {
                    $offenders[$rel] = ($offenders[$rel] ?? 0) + 1;
                }
            }
        }

        $this->assertSame([], $offenders, 'closure literals in per-request views (use Juwri\Core\Support\ViewHelpers): '.json_encode($offenders));
    }

    /** @return array<string, int> view path => closure literal count, for every view under resources/views */
    private function allClosureCounts(): array
    {
        $compiler = app('blade.compiler');
        $root = resource_path('views');
        $counts = [];
        foreach (File::allFiles($root) as $f) {
            if (! str_ends_with($f->getFilename(), '.blade.php')) {
                continue;
            }
            $n = 0;
            foreach (token_get_all($compiler->compileString((string) file_get_contents($f->getPathname()))) as $t) {
                if (is_array($t) && in_array($t[0], [T_FUNCTION, T_FN], true)) {
                    $n++;
                }
            }
            if ($n > 0) {
                $counts[str_replace('\\', '/', $f->getRelativePathname())] = $n;
            }
        }
        ksort($counts);

        return $counts;
    }

    public function test_no_view_gains_closures_and_the_remaining_list_only_shrinks(): void
    {
        $allow = ViewClosureAllowlist::REMAINING;
        $new = [];
        $stale = [];
        $actual = $this->allClosureCounts();
        foreach ($actual as $rel => $n) {
            if (($allow[$rel] ?? 0) < $n) {
                $new[$rel] = $n;
            }
        }
        foreach ($allow as $rel => $n) {
            if (($actual[$rel] ?? 0) < $n) {
                $stale[$rel] = $actual[$rel] ?? 0; // fewer than listed: lower the number (or delete the line at 0)
            }
        }

        $this->assertSame([], $new, 'views with more closure literals than ViewClosureAllowlist permits: '.json_encode($new));
        $this->assertSame([], $stale, 'ViewClosureAllowlist is out of date (lower these counts, delete at 0): '.json_encode($stale));
    }

    public function test_rendering_card_and_trendchart_does_not_grow_memory(): void
    {
        $render = fn () => Blade::render(
            '<x-ui.card variant="a b" title="T">x</x-ui.card><x-ui.trendchart :series="[[\'name\' => \'S\', \'data\' => [1, 3, 2, 5]]]" />'
        );
        foreach (range(1, 300) as $i) {
            $render();
        }
        gc_collect_cycles();
        $before = memory_get_usage();
        foreach (range(1, 1000) as $i) {
            $render();
        }
        gc_collect_cycles();

        // Blade::render compiles an inline template per call (a fresh temp file each time), so allow for that noise; a
        // closure per render in either component alone would add 250 KB.
        $this->assertLessThan(150 * 1024, memory_get_usage() - $before);
    }

    public function test_helpers_match_the_closures_they_replaced(): void
    {
        $this->assertSame('1,2 3,4', ViewHelpers::polyline([[1, 2], [3, 4]]));
        $this->assertSame('A, B', ViewHelpers::seriesLabel([['name' => 'A'], ['x' => 1], ['name' => ''], ['name' => 'B']]));
        $this->assertSame('/', ViewHelpers::barePath('/en'));
        $this->assertSame('/orders', ViewHelpers::barePath('/fr/orders/?x=1'));
        $this->assertSame('/', ViewHelpers::barePath(null));
        $this->assertSame(['ui-card--a', 'ui-card--b'], ViewHelpers::cardModifiers(' a  b '));
        $this->assertSame([], ViewHelpers::cardModifiers(null));
        $this->assertSame([['type' => 'ok', 'message' => 'm']], ViewHelpers::flashPayload([['type' => 'ok', 'message' => 'm', 'extra' => 1]]));
        $this->assertSame('+5%', ViewHelpers::trendText(5));
        $this->assertSame('-2.5%', ViewHelpers::trendText(-2.5));
        $this->assertSame('0%', ViewHelpers::trendText(0));
        $this->assertSame(['up', 'down', 'flat'], [ViewHelpers::trendState(1), ViewHelpers::trendState(-1), ViewHelpers::trendState('x')]);
        $this->assertSame(7, ViewHelpers::maxQty([['qty' => 7], ['qty' => 2]]));
        $this->assertSame(1, ViewHelpers::maxQty([]));
        $this->assertSame(1, ViewHelpers::maxQty([['qty' => 0]]));
        $this->assertSame(1, ViewHelpers::maxLanguageCount([]));
        $this->assertSame(9, ViewHelpers::maxLanguageCount(collect([(object) ['count' => 9], (object) ['count' => 3]])));
        $this->assertSame(12, ViewHelpers::sumPrices([(object) ['price' => 10], (object) ['price' => null], (object) ['price' => 2]]));
        $this->assertSame(1, ViewHelpers::firstUndone([['done' => true], ['done' => false]]));
        $this->assertFalse(ViewHelpers::firstUndone([['done' => true]]));
        $this->assertSame('b, c', ViewHelpers::extraTitles([(object) ['extra' => (object) ['title' => 'b']], (object) ['extra' => null], (object) ['extra' => (object) ['title' => 'c']]]));
        $this->assertSame('1 Main, Town', ViewHelpers::addressLabel((object) ['line' => '1 Main', 'city' => 'Town', 'state' => null, 'zip' => '', 'country' => null]));
        $this->assertSame([2, 4], ViewHelpers::withItems([(object) ['id' => 1], (object) ['id' => 2], (object) ['id' => 4]], [2 => 1, 4 => 3])->pluck('id')->all());
        $this->assertSame('translated', ViewHelpers::translatedValue('fr', ['fr' => ['title' => 'translated']], 'title', 'fallback'));
        $this->assertSame('fallback', ViewHelpers::translatedValue('de', ['fr' => ['title' => 'translated']], 'title', 'fallback'));
        $this->assertSame('$1,234.50', (new PriceFormatter('$', '1', 2))(1234.5));
        $this->assertSame('16.00 kr', (new PriceFormatter(' kr', '0', 2))(16));
    }
}
