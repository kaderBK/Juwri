<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use Juwri\Modules\Payments\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PCI: the 2Checkout card page must never submit card data to our server.
 * The number / expiry / CVV inputs carry no `name` (the page script reads
 * them by id and posts only the one-time token), and TCO.loadPubKey is given
 * the environment, not the publishable key.
 */
class TwoCheckoutCardPageTest extends TestCase
{
    use RefreshDatabase;

    private function render(mixed $sandbox): string
    {
        $transaction = new Transaction(['product_name' => 'Pro', 'amount' => 10]);
        $transaction->id = 7;

        return view('owner.billing.gateways.two-checkout', [
            'transaction' => $transaction,
            'checkout_seller_id' => '901',
            'checkout_private_key' => 'fake-private',
            'checkout_public_key' => 'fake-public-key',
            'sandbox' => $sandbox,
            'color' => '#000000',
            'cancel_url' => '/cancel',
        ])->render();
    }

    public function test_card_inputs_have_no_name_so_the_card_never_reaches_the_server(): void
    {
        $html = $this->render('production');

        foreach (['tco-number', 'tco-expiry', 'tco-cvc'] as $id) {
            $this->assertMatchesRegularExpression('/<input id="'.$id.'"[^>]*>/', $html);
            preg_match('/<input id="'.$id.'"[^>]*>/', $html, $m);
            $this->assertStringNotContainsString('name=', $m[0], $id.' must not be submitted');
        }
        foreach (['checkoutCardNumber', 'checkoutCardExpiry', 'checkoutCardCVC'] as $old) {
            $this->assertStringNotContainsString($old, $html);
        }
        $this->assertStringContainsString('name="order_id"', $html);
    }

    public function test_load_pub_key_gets_the_environment(): void
    {
        $this->assertStringContainsString('var environment = "production"', $this->render('production'));
        $this->assertStringContainsString('var environment = "sandbox"', $this->render('sandbox'));
        $this->assertStringContainsString('var environment = "sandbox"', $this->render(true));
        $this->assertStringContainsString('TCO.loadPubKey(environment)', $this->render(false));
        $this->assertStringNotContainsString('TCO.loadPubKey(publicKey)', $this->render(false));
    }
}
