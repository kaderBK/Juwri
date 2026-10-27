<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use Juwri\Core\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * I5 §7 — in-store customer screens (signage pickup/menu, kiosk attract) render
 * in the store's language + direction instead of hardcoded English.
 */
class SignageLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_pickup_board_renders_arabic_with_rtl(): void
    {
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);
        App::setLocale('ar');

        $screen = (object) ['token' => 'abc123'];
        $html = view('signage.pickup', [
            'screen' => $screen,
            'data' => ['preparing' => [], 'ready' => []],
        ])->render();

        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('جار التحضير', $html); // Preparing
        $this->assertStringContainsString('جاهز', $html);         // Ready
        $this->assertStringContainsString('حالة الطلب', $html);   // Order status (title)
        $this->assertStringNotContainsString('<html lang="en">', $html);
    }

    public function test_signage_views_are_wired_for_locale_and_direction(): void
    {
        foreach (['signage/pickup', 'signage/menu', 'kiosk/attract'] as $view) {
            $src = file_get_contents(resource_path("views/{$view}.blade.php"));
            $this->assertStringNotContainsString('<html lang="en">', $src, "{$view} still hardcodes lang=en");
            $this->assertStringContainsString('get_lang()', $src, "{$view} not wired to get_lang()");
            $this->assertStringContainsString('___(', $src, "{$view} has no ___() calls");
        }
    }
}
