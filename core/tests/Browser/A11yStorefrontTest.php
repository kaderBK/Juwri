<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Tests\Browser\Support\Axe;
use Tests\Browser\Support\Fixtures;

/** P1-18 a11y: the crave storefront has no axe-core WCAG 2.1 level A/AA violations on its landing page. */
it('has no axe-core WCAG 2.1 AA violations on the storefront', function () {
    $store = Fixtures::store(Fixtures::owner());

    // the splash lines fade in (~1s opacity animation); axe would read half-transparent mid-frame colours
    $page = visit('/'.$store->slug)->wait(2);

    Axe::assertClean($page, 'crave storefront /'.$store->slug);
});
