<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Architecture;

use Illuminate\Support\Facades\File;
use Juwri\Core\Editions\EditionPackages;
use Tests\TestCase;

/**
 * fix-xss M1: assets/js/csp-actions.js turns data-act / data-change / data-input / data-search into a call of a page
 * function. A free-form window lookup let any injected markup call eval, Function, fetch, location.assign or open
 * (the dashboard CSP allows 'unsafe-eval'), so the dispatcher only calls names on its explicit allowlist. This test
 * keeps the allowlist free of dangerous globals and complete for every handler name the markup uses.
 */
class CspActionsAllowlistTest extends TestCase
{
    private const DANGEROUS = ['eval', 'Function', 'setTimeout', 'setInterval', 'fetch', 'open', 'alert', 'prompt', 'confirm',
        'location', 'importScripts', 'postMessage', 'execScript', 'XMLHttpRequest', 'Worker', 'document', 'window', 'self',
        'top', 'parent', 'frames', 'globalThis', 'localStorage', 'sessionStorage', 'navigator', 'history'];

    private function source(): string
    {
        return File::get(dirname(base_path()).'/assets/js/csp-actions.js');
    }

    /** @return list<string> */
    private function allowlist(): array
    {
        $this->assertSame(1, preg_match('~var ALLOWED = \[(.*?)\];~s', $this->source(), $m), 'csp-actions.js declares var ALLOWED = [...]');
        preg_match_all("~'([^']+)'~", $m[1], $names);

        return $names[1];
    }

    public function test_dispatcher_only_calls_allowlisted_names(): void
    {
        $src = $this->source();

        $this->assertStringContainsString('if (!name || !Object.prototype.hasOwnProperty.call(ALLOW, name)) return;', $src);
        $this->assertStringContainsString('if (!Object.prototype.hasOwnProperty.call(fn, parts[i])) return;', $src, 'own properties only, no prototype walk');
        $this->assertStringContainsString("return (u.protocol === 'https:' || u.protocol === 'http:') ? u.href : null;", $src, 'URL helpers refuse javascript: and data:');
        $this->assertStringNotContainsString('location.assign(url)', $src);
        $this->assertStringNotContainsString('window.open(el.dataset[key]', $src);
    }

    public function test_allowlist_has_no_dangerous_global(): void
    {
        foreach ($this->allowlist() as $name) {
            // the global itself (or the receiver of a dotted name) must not be a dangerous one
            $this->assertNotContains(explode('.', $name)[0], self::DANGEROUS, "{$name} must not be callable from markup");
            $this->assertMatchesRegularExpression('~^[A-Za-z_]\w*(\.[A-Za-z_]\w*)?$~', $name);
        }
    }

    public function test_every_handler_name_in_markup_is_allowlisted(): void
    {
        $allowed = $this->allowlist();
        $missing = [];
        $roots = [resource_path('views'), ...array_column(EditionPackages::all(), 'path'), app_path(), dirname(base_path()).'/assets'];
        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }
            foreach (File::allFiles($root) as $file) {
                $path = $file->getPathname();
                if (! preg_match('/\.(php|js|html)$/', $path) || str_ends_with($path, 'csp-actions.js')
                    || preg_match('~/(tests|vendor|node_modules|theme-studio)/~', $path)) {
                    continue;
                }
                // SEC-1120 NIT 1: data-search is delegated too, and JS-built markup quotes the name with escaped quotes.
                preg_match_all('~data-(?:act|change|input|search)=\\\\?(["\'])([A-Za-z_]\w*(?:\.[A-Za-z_]\w*)*)\\\\?\1~', $file->getContents(), $m);
                foreach ($m[2] as $name) {
                    if (! in_array($name, $allowed, true)) {
                        $missing[$name] = $file->getRelativePathname();
                    }
                }
            }
        }

        $this->assertSame([], $missing, 'handler names used in markup but missing from csp-actions.js ALLOWED');
        $this->assertContains('submitBrowse', $allowed, 'data-search="submitBrowse" on the marketplace browse forms');
    }
}
