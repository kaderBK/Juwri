<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Delivery;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Security review #4: the dispatch map builds its order / driver lists with
 * innerHTML from the map feed. The order address comes straight from a
 * public diner's checkout and the driver name from driver data, so every
 * feed string must pass through the HTML escaper before it reaches markup
 * (Alpine is loaded on the dashboard, so injected x-* attributes would run).
 */
class DispatchMapXssTest extends TestCase
{
    private function script(string $view): string
    {
        $src = File::get(resource_path('views/'.$view));
        $this->assertSame(1, preg_match('/<script nonce[^>]*>(.*?)<\/script>/s', $src, $m));

        return $m[1];
    }

    public function test_dispatch_map_escapes_every_feed_string_before_innerhtml(): void
    {
        $js = $this->script('owner/dispatch/map.blade.php');

        $this->assertStringContainsString('function esc(', $js);
        foreach (['${o.address', '${s.name', '${x.name', '${s.driver_id', '${o.id'] as $raw) {
            $this->assertStringNotContainsString($raw, $js, "raw feed value {$raw} reaches innerHTML");
        }
        foreach (['esc(o.address', 'esc(s.name', 'esc(x.name'] as $escaped) {
            $this->assertStringContainsString($escaped, $js);
        }
    }

    /** Opus review SHOULD 5: the driver PWA renders the same diner-typed address/phone with innerHTML. */
    public function test_driver_shell_escapes_every_feed_string_before_innerhtml(): void
    {
        $js = $this->script('driver/shell.blade.php');

        $this->assertStringContainsString('function esc(', $js);
        foreach (['${o.address', '${o.delivery_status', '${o.phone_number}', 'tel:${o.phone_number', '${o.id}', '${cash}'] as $raw) {
            $this->assertStringNotContainsString($raw, $js, "raw feed value {$raw} reaches innerHTML");
        }
        foreach (['esc(o.address', 'esc(o.delivery_status', 'esc(encodeURIComponent(o.phone_number'] as $escaped) {
            $this->assertStringContainsString($escaped, $js);
        }
    }

    /** fix-xss H1: the fleet driver app renders the same feed; numeric fields are coerced, not interpolated raw. */
    public function test_fleet_driver_app_escapes_or_coerces_every_feed_value_before_innerhtml(): void
    {
        $js = $this->script('driver/fleet-app.blade.php');

        $this->assertStringContainsString('const esc = ', $js);
        foreach (['${o.id}', '${it.quantity}', '${d.deliveries}', '${(j.summary || {}).deliveries', '${o.address', 'tel:${o.phone_number', '${o.phone_number}'] as $raw) {
            $this->assertStringNotContainsString($raw, $js, "raw feed value {$raw} reaches innerHTML");
        }
        foreach (['esc(o.address', 'esc(o.phone_number', 'Number(o.id)', 'Number(it.quantity)', 'Number(d.deliveries)'] as $safe) {
            $this->assertStringContainsString($safe, $js);
        }
    }

    public function test_floor_map_new_tile_escapes_table_no_and_section(): void
    {
        $js = $this->script('owner/tables/floor-map.blade.php');

        $this->assertStringNotContainsString("'<strong>T' + t.table_no", $js);
        $this->assertStringNotContainsString("+ (t.section || '') +", $js);
        $this->assertStringContainsString("'<strong>T' + escTt(t.table_no)", $js);
        $this->assertStringContainsString('escTt(t.section)', $js);
    }
}
