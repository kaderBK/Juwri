<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Verticals;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Juwri\Core\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * rev/mv-marketing — the /features page carries a self-contained "more than
 * restaurants" section presenting the vertical capabilities (variants/SKU,
 * inventory, OTC pharmacy, fitment, bookings, hotel folio). Kept out of the
 * shared feature-defs roster so the curated home grid is unaffected.
 */
class MvFeaturesVerticalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    public function test_features_page_shows_the_vertical_capabilities_section(): void
    {
        app()->setLocale('en');

        $html = $this->get(route('features'))->assertOk()->getContent();

        $this->assertStringContainsString('The same engine, tuned for your trade', $html);
        $this->assertStringContainsString('id="verticals"', $html);
        // A capability from each group is present.
        $this->assertStringContainsString('Products, variants', $html);   // goods
        $this->assertStringContainsString('Auto-parts fitment', $html);   // car-parts
        $this->assertStringContainsString('Rooms &amp; folio', $html);    // hotel
    }
}
