<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Unit;

use Tests\TestCase;

/**
 * The storefront reads the visitor's language from a superglobal (`$_COOKIE['Quick_user_lang_code']`). A test that sets it must not
 * change what the next test in the same process sees (the intermittent failures of KitDemoSeederTest / BrewViewModelTest /
 * CustomBlocksTest in parallel runs). The two tests below run in file order in one process: the first leaves the cookie behind on
 * purpose, the second proves TestCase cleared it.
 */
class TestCaseGlobalsIsolationTest extends TestCase
{
    public function test_a_a_test_may_leave_the_language_cookie_behind(): void
    {
        $_COOKIE['Quick_user_lang_code'] = 'ar';
        $this->assertSame('ar', $_COOKIE['Quick_user_lang_code']);
    }

    public function test_b_the_next_test_starts_without_it(): void
    {
        $this->assertArrayNotHasKey('Quick_user_lang_code', $_COOKIE);
    }
}
