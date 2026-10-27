<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Support\ViewRoots;

/**
 * P6-115: the Content-Security-Policy is enforced (config/csp.php) without 'unsafe-inline' for script attributes, so an
 * inline on*="..." handler (onclick, onchange, onerror, onload...) or a javascript: URL silently does nothing. P6-95
 * found the pizzaflow dish-card onerror dead; a sweep found ~100 more across the staff/driver PWAs, order hub,
 * marketplace, app store and the theme layouts. Handlers are now delegated: page code uses data-act / data-change
 * (assets/js/csp-actions.js) and the theme layout understands data-csp-error / data-csp-load / data-csp-toggle.
 *
 * Static and file based. Scans every Blade view root and the first-party JS under assets/ (JS strings that build HTML).
 * Property assignments (el.onclick = fn) and comment lines are fine; only markup attributes count.
 */
class NoInlineEventHandlersTest extends TestCase
{
    /**
     * Files allowed to contain an inline handler (path relative to its root => reason). Keep empty unless the file is
     * served somewhere the CSP is not applied.
     *
     * @var array<string, string>
     */
    private const ALLOW = [];

    private const ATTR = '/(?<=[\s"\'`])on(?:click|dblclick|change|submit|error|load|input|keyup|keydown|keypress|focus|blur|mouse\w+|touch\w+|search|select|toggle|scroll|resize|drop|drag\w*)\s*=\s*\\\\?["\']/i';

    private const JSURL = '/\bhref\s*=\s*\\\\?["\']\s*javascript:/i';

    public function test_no_inline_event_handlers_in_blade_views(): void
    {
        $bad = [];
        $roots = [...ViewRoots::all(), ...(glob(dirname(__DIR__, 2).'/plugins/*/resources/views', GLOB_ONLYDIR) ?: [])];
        foreach ($roots as $root) {
            foreach ($this->files($root, '/\.blade\.php$/') as $file) {
                $rel = str_starts_with($file, dirname(__DIR__, 2).'/plugins/') ? substr($file, strlen(dirname(__DIR__, 2)) + 1) : ViewRoots::relative($file);
                if (isset(self::ALLOW[$rel])) {
                    continue;
                }
                foreach ($this->offending($file) as $line) {
                    $bad[] = $rel.':'.$line;
                }
            }
        }

        $this->assertSame([], $bad, "Inline on*= handlers are dead under the enforced CSP. Use data-act/data-change (assets/js/csp-actions.js) or the theme layout's data-csp-* attributes:\n".implode("\n", $bad));
    }

    public function test_no_inline_event_handlers_in_first_party_asset_js(): void
    {
        $assets = dirname(__DIR__, 3).'/assets';
        $bad = [];
        foreach ($this->files($assets, '/(?<!\.min)\.js$/') as $file) {
            $rel = substr($file, strlen($assets) + 1);
            if (preg_match('#^(vendor|global|liquid-library|locked-templates)/#', $rel) || isset(self::ALLOW[$rel])) {
                continue;
            }
            foreach ($this->offending($file) as $line) {
                $bad[] = 'assets/'.$rel.':'.$line;
            }
        }

        $this->assertSame([], $bad, "Inline on*= handlers in JS-built HTML are dead under the enforced CSP:\n".implode("\n", $bad));
    }

    /** @return list<int> 1-based line numbers carrying an inline handler attribute or a javascript: href */
    private function offending(string $file): array
    {
        $lines = [];
        foreach (file($file) ?: [] as $i => $line) {
            $t = ltrim($line);
            if (str_starts_with($t, '//') || str_starts_with($t, '*') || str_starts_with($t, '/*') || str_starts_with($t, '{{--')) {
                continue;
            }
            if (preg_match(self::ATTR, $line) === 1 || preg_match(self::JSURL, $line) === 1) {
                $lines[] = $i + 1;
            }
        }

        return $lines;
    }

    /** @return iterable<string> */
    private function files(string $dir, string $pattern): iterable
    {
        if (! is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && preg_match($pattern, $f->getPathname()) === 1) {
                yield $f->getPathname();
            }
        }
    }
}
