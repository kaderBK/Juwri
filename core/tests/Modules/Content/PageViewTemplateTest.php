<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use Tests\TestCase;

/**
 * S2-P1: the Page View post_template is display-only. These assertions lock the
 * core invariant (no ordering surface, own no-cart layout) without needing the
 * full public-view controller stack + a browser.
 */
class PageViewTemplateTest extends TestCase
{
    private function index(): string
    {
        return file_get_contents(resource_path('views/store_templates/pageview/index.blade.php'));
    }

    private function layout(): string
    {
        return file_get_contents(resource_path('views/store_templates/pageview/layout.blade.php'));
    }

    public function test_template_files_and_registration_exist(): void
    {
        $this->assertFileExists(resource_path('views/store_templates/pageview/index.blade.php'));
        $this->assertFileExists(resource_path('views/store_templates/pageview/layout.blade.php'));
        // theme-info.txt makes it auto-discoverable in the picker.
        $this->assertFileExists(public_path('assets/store_templates/pageview/theme-info.txt'));
        $this->assertFileExists(public_path('assets/store_templates/pageview/preview.png'));
    }

    public function test_does_not_extend_the_cart_layout(): void
    {
        // The shared store_templates/layout.blade.php injects cart/checkout/waiter.
        $this->assertStringContainsString('store_templates.pageview.layout', $this->index());
        $this->assertStringNotContainsString("@extends('store_templates.layout')", $this->index());
    }

    public function test_no_ordering_surface_anywhere(): void
    {
        foreach ([$this->index(), $this->layout()] as $src) {
            foreach (['view-order', 'call-the-waiter', 'add-to-cart', 'addtocart', 'send order', 'checkout', 'basket'] as $needle) {
                $this->assertStringNotContainsStringIgnoringCase($needle, $src, "Page View must not contain ordering token: {$needle}");
            }
        }
    }

    public function test_renders_menu_data_and_controls(): void
    {
        $src = $this->index();
        $this->assertStringContainsString('total_menus', $src);       // reads the menu data
        $this->assertStringContainsString('pv-page', $src);           // paginated pages
        $this->assertStringContainsString('price_symbol_format', $src); // prices shown
        $this->assertStringContainsString('pvNext', $src);            // navigation controls
    }

    public function test_mode_b_pdf_support(): void
    {
        $src = $this->index();
        $this->assertStringContainsString('restaurant_pageview_source', $src); // source flag
        $this->assertStringContainsString('data-pdf-url', $src);               // PDF stage hook
        $this->assertStringContainsString('renderPdfUrlToPages', $src);        // client PDF render
    }

    public function test_theme_json_customizer_exists(): void
    {
        $path = public_path('assets/store_templates/pageview/theme.json');
        $this->assertFileExists($path);
        $json = json_decode(file_get_contents($path), true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('settings', $json);
    }
}
