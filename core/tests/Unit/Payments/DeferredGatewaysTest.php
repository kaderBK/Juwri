<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Unit\Payments;

use Juwri\Core\Support\DeferredGateways;
use Tests\TestCase;

/**
 * J51a: DeferredGateways exposes the deferred gateway folders only while the
 * payments.show_deferred_gateways flag is off, and force-off any store switch
 * for a hidden gateway that a posted option map still carries as on.
 */
class DeferredGatewaysTest extends TestCase
{
    public function test_hidden_returns_the_deferred_gateways_while_the_flag_is_off(): void
    {
        config(['payments.show_deferred_gateways' => false]);
        config(['payments.deferred_gateways' => ['tap', 'moyasar']]);

        $this->assertSame(['tap', 'moyasar'], DeferredGateways::hidden());
    }

    public function test_hidden_is_empty_while_the_flag_is_on(): void
    {
        config(['payments.show_deferred_gateways' => true]);
        config(['payments.deferred_gateways' => ['tap', 'moyasar']]);

        $this->assertSame([], DeferredGateways::hidden());
    }

    public function test_is_hidden_matches_only_the_deferred_list(): void
    {
        config(['payments.show_deferred_gateways' => false]);

        $this->assertTrue(DeferredGateways::isHidden('tap'));
        $this->assertTrue(DeferredGateways::isHidden('moyasar'));
        $this->assertFalse(DeferredGateways::isHidden('stripe'));
    }

    public function test_visible_removes_hidden_keys_from_a_gateway_map(): void
    {
        config(['payments.show_deferred_gateways' => false]);

        $this->assertSame(
            ['stripe' => 's', 'paypal' => 'p'],
            DeferredGateways::visible(['tap' => 't', 'stripe' => 's', 'moyasar' => 'm', 'paypal' => 'p']),
        );
    }

    public function test_filter_options_forces_a_hidden_gateway_switch_off(): void
    {
        config(['payments.show_deferred_gateways' => false]);

        $this->assertSame(
            [
                'restaurant_tap_install' => '0',
                'restaurant_moyasar_install' => '0',
                'restaurant_stripe_install' => '1',
                'other' => 'x',
            ],
            DeferredGateways::filterOptions([
                'restaurant_tap_install' => '1',
                'restaurant_moyasar_install' => 1,
                'restaurant_stripe_install' => '1',
                'other' => 'x',
            ]),
        );
    }

    public function test_filter_options_treats_every_truthy_spelling_as_on(): void
    {
        config(['payments.show_deferred_gateways' => false]);

        foreach ([1, '1', true, 'on', 'yes'] as $value) {
            $this->assertSame(
                ['restaurant_tap_install' => '0'],
                DeferredGateways::filterOptions(['restaurant_tap_install' => $value]),
            );
        }
    }

    public function test_filter_options_leaves_non_truthy_values_untouched(): void
    {
        config(['payments.show_deferred_gateways' => false]);

        $this->assertSame(
            ['restaurant_tap_install' => 'true', 'restaurant_moyasar_install' => ''],
            DeferredGateways::filterOptions(['restaurant_tap_install' => 'true', 'restaurant_moyasar_install' => '']),
        );
    }

    public function test_filter_options_does_nothing_while_the_flag_is_on(): void
    {
        config(['payments.show_deferred_gateways' => true]);

        $this->assertSame(
            ['restaurant_tap_install' => '1'],
            DeferredGateways::filterOptions(['restaurant_tap_install' => '1']),
        );
    }
}
