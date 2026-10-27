<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Juwri\Modules\Payments\Services\Gateways\MoyasarAdapter;
use Juwri\Modules\Payments\Services\Gateways\PaypalAdapter;
use Juwri\Modules\Payments\Services\Gateways\PaystackAdapter;
use Juwri\Modules\Payments\Services\Gateways\RazorpayAdapter;
use Juwri\Modules\Payments\Services\Gateways\StripeAdapter;
use Juwri\Modules\Payments\Services\Gateways\TapAdapter;

return [

    /*
    |--------------------------------------------------------------------------
    | Original-payment refund drivers
    |--------------------------------------------------------------------------
    |
    | Per-gateway Juwri\Modules\Payments\Services\Refunds\GatewayRefunder implementations that push
    | a refund back to the diner's original card/gateway. List a driver class
    | here once its live refund call is implemented AND verified against a real
    | provider account. Any gateway without a driver falls back to a tracked
    | manual refund (owner completes it in the provider dashboard).
    |
    | Example: App\Services\Refunds\Drivers\StripeRefunder::class
    |
    */
    'drivers' => [
        // gateways with a verified live refund driver go here
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment adapters whose refund() is offered (WI-06)
    |--------------------------------------------------------------------------
    |
    | Keyed by `payment_folder`. Automatic refunds are OFF unless switched on:
    | per store (post option `auto_refunds_enabled` = 1) for store orders, and
    | platform-wide (setting `auto_refunds_enabled` = 1) for platform payments.
    | While off, a refund is recorded as a tracked manual refund and no provider
    | is called. Owner decision 2026-09-27: these four only; the other adapters
    | keep refund() implemented but unregistered.
    |
    */
    'adapters' => [
        'stripe' => StripeAdapter::class,
        'paypal' => PaypalAdapter::class,
        'razorpay' => RazorpayAdapter::class,
        'paystack' => PaystackAdapter::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Adapters a store opts into on its own (owner decision 2026-10-07)
    |--------------------------------------------------------------------------
    |
    | Tap and Moyasar: registered for a store's refunds only when its owner
    | switched on post option `auto_refunds_tap_moyasar` (default off) on top of
    | `auto_refunds_enabled`. Off: refunds on them stay a manual step. Never used
    | for platform payments.
    |
    */
    'store_opt_in' => [
        'tap' => TapAdapter::class,
        'moyasar' => MoyasarAdapter::class,
    ],

];
