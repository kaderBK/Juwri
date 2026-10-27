<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Browser\Support;

use Closure;

/**
 * P6-115b: helpers for browser tests that must prove a control works UNDER an enforced Content-Security-Policy.
 *
 * `arm()` is called once the page has loaded. It (1) collects every `securitypolicyviolation` event the page raises from then
 * on (a CSP-dead inline handler that is clicked raises one), and (2) replaces `confirm()` / `prompt()` with recorders that
 * answer from `window.__answers`, so a test can say "the dialog appeared, with this text" and choose OK or Cancel.
 */
final class CspProbe
{
    public static function arm(object $page, bool $confirm = true, ?string $prompt = null): void
    {
        $page->script(<<<'JS'
() => {
    window.__csp = [];
    window.__dialogs = [];
    window.__answers = { confirm: true, prompt: null };
    if (!window.__cspArmed) {
        window.__cspArmed = true;
        document.addEventListener('securitypolicyviolation', function (e) {
            window.__csp.push(e.violatedDirective + ' | ' + e.blockedURI + ' | ' + (e.sample || ''));
        });
        window.confirm = function (m) { window.__dialogs.push(['confirm', String(m)]); return window.__answers.confirm; };
        window.prompt = function (m) { window.__dialogs.push(['prompt', String(m)]); return window.__answers.prompt; };
    }
    return true;
}
JS);
        self::answers($page, $confirm, $prompt);
    }

    /** Choose what the next confirm() / prompt() calls return (null prompt = the user cancelled it). */
    public static function answers(object $page, bool $confirm, ?string $prompt = null): void
    {
        $page->script('() => { window.__answers = { confirm: '.($confirm ? 'true' : 'false').', prompt: '.($prompt === null ? 'null' : json_encode($prompt)).' }; return true; }');
    }

    /** @return list<array{0:string,1:string}> the confirm()/prompt() calls the page made since arm() (or clearDialogs()) */
    public static function dialogs(object $page): array
    {
        return $page->script('() => window.__dialogs || []');
    }

    public static function clearDialogs(object $page): void
    {
        $page->script('() => { window.__dialogs = []; return true; }');
    }

    /**
     * The functional CSP violations the page raised since arm(): blocked scripts and inline handlers (script-src*), blocked
     * requests (connect-src) and blocked form posts (form-action). Styling and image directives are not what a dead
     * onclick= looks like and are left out.
     *
     * @return list<string>
     */
    public static function violations(object $page): array
    {
        $all = $page->script('() => window.__csp || []');

        return array_values(array_filter($all, fn (string $v) => (bool) preg_match('/^(script-src|connect-src|form-action)/', $v)));
    }

    /** The Content-Security-Policy header the current page was served with ('' = none, i.e. nothing is enforced). */
    public static function header(object $page): string
    {
        return (string) $page->script(<<<'JS'
async () => {
    const r = await fetch(location.href, { credentials: 'same-origin', headers: { Accept: 'text/html' } });
    return r.headers.get('content-security-policy') || '';
}
JS);
    }

    /** Elements still carrying a real on*= attribute (these do nothing under the enforced policy). */
    public static function inlineHandlers(object $page): int
    {
        return (int) $page->script("() => document.querySelectorAll('[onclick],[onchange],[onsubmit],[onerror],[onsearch],[oninput],[onfocus],[onload],[onkeydown]').length");
    }

    /** The policy is enforced (not report-only, not empty) and forbids inline script; no violation; no inline handler left. */
    public static function assertClean(object $page, bool $checkHeader = true): void
    {
        if ($checkHeader) {
            $csp = self::header($page);
            expect($csp)->not->toBe('', 'the page is served with an enforced Content-Security-Policy');
            expect($csp)->toContain('script-src');
            expect(preg_match("/script-src[^;]*'unsafe-inline'(?![^;]*nonce-)/", $csp))->toBe(0, 'script-src has no bare unsafe-inline: '.$csp);
        }
        expect(self::violations($page))->toBe([], 'no CSP violation was raised');
        expect(self::inlineHandlers($page))->toBe(0, 'no inline on*= handler is left in the DOM');
    }

    /** Poll (up to ~12 s) until $check() is truthy; the in-process server writes the row a moment after the click. */
    public static function until(object $page, Closure $check, string $why, int $tries = 24): void
    {
        for ($i = 0; $i < $tries; $i++) {
            if ($check()) {
                return;
            }
            $page->wait(0.5);
        }
        expect($check())->toBeTrue($why);
    }
}
