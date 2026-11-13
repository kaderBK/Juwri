<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Providers\ViewServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P1 sub-item 1 — the wildcard view composers must compute the settings/languages
 * globals ONCE per request and reuse them across every view instance, instead of
 * re-querying `options`/`languages` per @include/component.
 *
 * The invariant we assert is memoization-within-a-request: after a warm-up render,
 * further renders in the same request add ZERO `options`/`languages` queries. This is
 * robust to how many composer sets happen to be registered (the app may already have
 * registered them at boot when installed), which a raw "<= 1 total" count is not.
 */
class WildcardComposerQueryCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeat_renders_add_no_options_or_languages_queries(): void
    {
        config(['app.installed' => true]);
        view()->addLocation(base_path('tests/Fixtures/views'));

        // Ensure the real composers are active. If the app's normal boot did not
        // register them (test env boots APP_INSTALLED=false), register them once.
        if (! str_contains(view('p1probe')->render(), 'settings-ok')) {
            new ViewServiceProvider($this->app)->boot();
        }

        // Warm-up renders: whatever composer sets exist fire and populate their memos.
        view('p1probe')->render();
        $probe = view('p1probe')->render();
        $this->assertStringContainsString('settings-ok', $probe);
        $this->assertStringContainsString('languages-ok', $probe);

        // Now count: subsequent renders in the SAME request must not re-query.
        $optionQueries = 0;
        $languageQueries = 0;
        DB::listen(function ($q) use (&$optionQueries, &$languageQueries) {
            if (preg_match('/from\s+["`]?options["`]?/i', $q->sql)) {
                $optionQueries++;
            }
            if (preg_match('/from\s+["`]?languages["`]?/i', $q->sql)) {
                $languageQueries++;
            }
        });

        view('p1probe')->render();
        view('p1probe')->render();
        view('p1probe')->render();

        $this->assertSame(0, $optionQueries, 'repeat renders must not re-query options (memoized per request)');
        $this->assertSame(0, $languageQueries, 'repeat renders must not re-query languages (memoized per request)');
    }
}
