<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\Kit;

use Juwri\Modules\Storefront\Services\Theme\ThemeAliases;
use Tests\TestCase;

/** Owner decision 2026-10-08: theme aliases are OFF unless JUWRI_THEME_ALIASES turns them on. */
class ThemeAliasSwitchTest extends TestCase
{
    public function test_the_shipped_default_is_off(): void
    {
        $src = (string) file_get_contents(config_path('theme-successors.php'));
        $this->assertStringContainsString("env('JUWRI_THEME_ALIASES', false)", $src);
        $this->assertFalse(ThemeAliases::enabled(), 'phpunit runs with the env flag false');
        $this->assertNull(ThemeAliases::target('crave'));
    }

    public function test_the_flag_turns_them_on_and_back_off(): void
    {
        config(['theme-successors.enabled' => true]);
        $this->assertTrue(ThemeAliases::enabled());
        $this->assertSame('table', ThemeAliases::effective('crave'));

        config(['theme-successors.enabled' => false]);
        $this->assertFalse(ThemeAliases::enabled());
        $this->assertSame('crave', ThemeAliases::effective('crave'));
    }
}
