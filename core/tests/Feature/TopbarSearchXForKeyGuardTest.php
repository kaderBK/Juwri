<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Regression guard for the dashboard-wide Alpine crash.
 *
 * The topbar quick-search renders its result rows with
 * `<template x-for="(item, idx) in results" :key="...">`. `results` is the
 * client-side nav list (plus server entity hits, or the recents / top-5 slice
 * shown on load), and it can legitimately contain the SAME url twice — one help
 * article reachable from several nav rows — or an entry with no url at all.
 *
 * Keying that x-for by `item.url` therefore produced DUPLICATE and UNDEFINED
 * Alpine keys. Alpine v3's keyed reconcile then crashes with
 * "TypeError: Cannot read properties of undefined (reading 'after')" and aborts
 * the topbar's Alpine init — on EVERY dashboard page, three exceptions per load.
 * (This is a sibling of the PR #111 `<template x-if>`-in-`<dialog>` crash: same
 * Alpine DOM-insertion path, different trigger — a bad :key, not a dialog.)
 *
 * The fix keys by the loop index, which is always unique and always defined. The
 * list re-renders wholesale per keystroke and the rows hold no local state, so a
 * positional key is correct here. This test freezes that: the results x-for must
 * never key by a per-item field that can collide or be undefined.
 */
class TopbarSearchXForKeyGuardTest extends TestCase
{
    private function topbar(): string
    {
        return file_get_contents(
            resource_path('views/components/ui/shell/topbar.blade.php')
        );
    }

    public function test_search_results_xfor_keys_by_index_not_item_url(): void
    {
        $view = $this->topbar();

        // The results loop must key by the (unique, always-defined) loop index.
        $this->assertMatchesRegularExpression(
            '/x-for="\(item, idx\) in results"\s+:key="idx"/',
            $view,
            'the topbar search x-for must key by idx (item.url can duplicate or be undefined and crashes Alpine)'
        );

        // And must never regress to keying by item.url.
        $this->assertStringNotContainsString(
            'x-for="(item, idx) in results" :key="item.url"',
            $view,
            'keying the search results by item.url reintroduces the duplicate/undefined-key Alpine crash'
        );
    }

    /**
     * Broader guard: no x-for anywhere in the shared topbar may key by a raw
     * `item.url` / `.url` field — that is the exact field shape that collides.
     * A new keyed loop over URL-bearing items must pick a unique key.
     */
    public function test_no_topbar_xfor_keys_by_a_url_field(): void
    {
        $view = $this->topbar();

        $this->assertDoesNotMatchRegularExpression(
            '/<template\s+x-for="[^"]*"\s+:key="[^"]*\.url"/',
            $view,
            'a shared-topbar x-for keyed by a .url field can collide/be undefined and crash Alpine init dashboard-wide'
        );
    }
}
