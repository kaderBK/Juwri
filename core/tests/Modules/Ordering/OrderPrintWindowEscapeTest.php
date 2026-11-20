<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * fix-xss L3: the order print popup is written with document.write; the store title / address (and the title and
 * thanks text) are escaped first — @js only makes them safe as JavaScript literals, not as HTML.
 */
class OrderPrintWindowEscapeTest extends TestCase
{
    public function test_print_window_escapes_store_title_and_address(): void
    {
        $src = File::get(resource_path('views/owner/orders/index.blade.php'));
        $this->assertSame(1, preg_match('~printDrawer: function \(\) \{(.*?)\n            \},~s', $src, $m));
        $js = $m[1];

        foreach (["'<h3>' + config.restaurantName", "'<p>' + (config.restaurantAddress", "'<title>' + this.drawerTitle", "'<p>' + config.thanksText"] as $raw) {
            $this->assertStringNotContainsString($raw, $js, "raw value {$raw} written to the print window");
        }
        foreach (['pe(config.restaurantName)', 'pe(config.restaurantAddress)', 'pe(this.drawerTitle)', 'pe(config.thanksText)'] as $escaped) {
            $this->assertStringContainsString($escaped, $js);
        }
    }
}
