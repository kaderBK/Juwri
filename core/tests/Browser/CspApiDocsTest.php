<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Tests\Browser\Support\CspProbe;

/**
 * P6-132: the API docs page renders the Stoplight Elements reference under its enforced policy with ZERO violations: the
 * web component is defined, the spec bootstrap ran (operations listed) and every stylesheet loaded.
 */
it('renders /docs/api under the enforced api-docs policy with zero violations', function () {
    config(['csp.enabled' => true, 'api.docs_public' => true]);

    $page = visit('/docs/api');
    $page->wait(4);
    CspProbe::arm($page);
    $page->wait(1);

    $page->assertNoConsoleLogs()->assertNoJavaScriptErrors();
    CspProbe::assertClean($page);
    expect(CspProbe::header($page))->toContain("connect-src 'self'")->not->toContain("'unsafe-eval'");
    expect((bool) $page->script("() => !!customElements.get('elements-api')"))->toBeTrue('the Elements web component loaded');
    expect((bool) $page->script("() => !!document.getElementById('docs').apiDescriptionDocument"))->toBeTrue('the nonced bootstrap ran');
    expect((int) $page->script("() => document.getElementById('docs').querySelectorAll('a[href*=\"#/\"]').length"))->toBeGreaterThan(0, 'the operations are listed');
    expect($page->script("() => [...document.querySelectorAll('link[rel=stylesheet]')].filter(l => !l.sheet).map(l => l.href)"))->toBe([]);
    // every inline script/style with content runs by nonce, except the Font Awesome sheet the bundle injects, which the
    // policy allows by hash; a refused <style> has no sheet, so "every style applied" is the load-time violation check
    expect($page->script("() => [...document.querySelectorAll('script:not([src]):not([type=\"application/json\"])')].filter(e => !e.nonce && e.textContent.trim() !== '').length"))->toBe(0);
    expect($page->script("() => [...document.querySelectorAll('style')].filter(e => !e.sheet).map(e => e.outerHTML.slice(0, 80))"))->toBe([]);
    expect((string) $page->script("() => getComputedStyle(document.documentElement).getPropertyValue('--fa-font-solid')"))->toContain('Font Awesome'); // the icon font sheet applied
});
