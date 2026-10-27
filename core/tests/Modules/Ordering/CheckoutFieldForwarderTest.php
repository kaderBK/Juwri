<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use Tests\TestCase;

/**
 * WIRING-RUN D4.
 *
 * The checkout field forwarder carries scheduled_at, gift_card_code, curbside/vehicle_*
 * and the hotel tender/room pair into the send-order POST. Themes submit orders through
 * a compiled bundle that builds its own payload, so without this interceptor those
 * fields are silently dropped — charge-to-room bookings post as ordinary orders.
 *
 * It used to be @included per theme and reached only 16 of 39 templates. It now lives in
 * the shared layout exactly once. This test pins both halves of that invariant.
 */
class CheckoutFieldForwarderTest extends TestCase
{
    private const PARTIAL = 'store_templates._partials.order-field-forwarder';

    public function test_the_shared_layout_includes_the_forwarder_exactly_once(): void
    {
        $layout = file_get_contents(base_path('resources/views/store_templates/layout.blade.php'));

        $this->assertSame(
            1,
            substr_count($layout, self::PARTIAL),
            'store_templates/layout.blade.php must include the checkout field forwarder exactly once.'
        );
    }

    /**
     * No theme may include it again: a second copy double-wraps fetch/XHR. Injection is
     * idempotent so it would not corrupt an order, but it is dead weight that hides
     * whether the layout copy is doing its job.
     */
    public function test_no_theme_includes_the_forwarder_a_second_time(): void
    {
        $offenders = [];

        foreach (glob(base_path('resources/views/store_templates/*/index.blade.php')) as $file) {
            if (str_contains(file_get_contents($file), self::PARTIAL)) {
                $offenders[] = basename(dirname($file));
            }
        }

        $this->assertSame([], $offenders, 'These themes re-include the forwarder already provided by the layout: '.implode(', ', $offenders));
    }

    /** Every field the hotel/scheduling/gift-card flows depend on must stay forwarded. */
    public function test_the_forwarder_carries_every_expected_checkout_field(): void
    {
        $partial = file_get_contents(base_path('resources/views/store_templates/_partials/order-field-forwarder.blade.php'));

        foreach (['scheduled_at', 'gift_card_code', 'curbside', 'vehicle_make', 'vehicle_color', 'vehicle_plate', 'tender', 'room', 'use_wallet'] as $field) {
            $this->assertStringContainsString("'{$field}'", $partial, "The forwarder no longer carries [{$field}] into the send-order POST.");
        }
    }
}
