<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Unit;

use Juwri\Core\Support\StripeManagedPayments;
use PHPUnit\Framework\TestCase;

class StripeManagedPaymentsTest extends TestCase
{
    public function test_a_digital_session_gains_managed_payments_and_loses_the_card_lock(): void
    {
        $shaped = StripeManagedPayments::session([
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'automatic_tax' => ['enabled' => true],
            'line_items' => [['quantity' => 1]],
            'success_url' => 'https://example.test/ok',
        ], true);

        $this->assertSame(['enabled' => true], $shaped['managed_payments']);
        $this->assertArrayNotHasKey('payment_method_types', $shaped);
        $this->assertArrayNotHasKey('automatic_tax', $shaped);
        $this->assertSame('payment', $shaped['mode']);
        $this->assertSame('https://example.test/ok', $shaped['success_url']);
    }

    public function test_the_flag_off_leaves_the_session_alone(): void
    {
        $original = [
            'mode' => 'payment',
            'payment_method_types' => ['card'],
        ];

        $this->assertSame($original, StripeManagedPayments::session($original, false));
    }

    public function test_a_product_receives_the_saas_tax_code_only_when_enabled(): void
    {
        $product = ['name' => 'Pro'];

        $this->assertSame(
            ['name' => 'Pro', 'tax_code' => StripeManagedPayments::TAX_SAAS_BUSINESS],
            StripeManagedPayments::product($product, StripeManagedPayments::TAX_SAAS_BUSINESS, true)
        );
        $this->assertSame($product, StripeManagedPayments::product($product, StripeManagedPayments::TAX_SAAS_BUSINESS, false));
    }

    public function test_request_options_pin_api_version_only_when_enabled(): void
    {
        $this->assertSame(
            ['stripe_version' => StripeManagedPayments::API_VERSION],
            StripeManagedPayments::requestOptions(true)
        );
        $this->assertSame([], StripeManagedPayments::requestOptions(false));
    }
}
