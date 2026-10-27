<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Tests\TestCase;

/**
 * SI12 (#80/#82): every studio view is translatable + RTL/direction-aware.
 * Locks the i18n/a11y invariant across the whole studio surface.
 */
class StudioI18nTest extends TestCase
{
    /** @return array<int, array<int,string>> */
    public static function studioViews(): array
    {
        return [
            ['studio/index.blade.php'],
            ['studio/share.blade.php'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('studioViews')]
    public function test_view_is_i18n_and_direction_aware(string $view): void
    {
        $src = file_get_contents(resource_path('views/'.$view));

        $this->assertStringContainsString('___(', $src, "{$view} must use ___() for translatable strings");
        $this->assertStringContainsString('current_language()', $src, "{$view} must set dir from current_language()");
        $this->assertStringContainsString('lang="{{ get_lang() }}"', $src, "{$view} must set the html lang attribute");
    }
}
