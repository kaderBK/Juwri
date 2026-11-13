<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods;

use Juwri\Modules\Payments\Services\Gateways\PaypalAdapter;
use Srmklive\PayPal\Services\PayPal;

/** PayPal — see PaypalAdapter. */
class PaypalController extends GatewayAdapterController
{
    /** PayPal's public sandbox application id (sandbox apps have no own id). */
    private const SANDBOX_APP_ID = 'APP-80W284485P519543T';

    protected static function adapterClass(): string
    {
        return PaypalAdapter::class;
    }

    /**
     * A signed-in srmklive/paypal client on the platform PayPal app, for the
     * account and add-on models that cancel a PayPal subscription
     * (`cancelSubscription($id, $reason)`). Credential errors are left to the caller.
     */
    public static function getPaypalProvider(): PayPal
    {
        $sandbox = gateway_is_sandbox(config('settings.paypal_sandbox_mode'), false);
        $mode = $sandbox ? 'sandbox' : 'live';

        $client = new PayPal([
            'mode' => $mode,
            $mode => [
                'client_id' => (string) config('settings.paypal_api_client_id'),
                'client_secret' => (string) config('settings.paypal_api_secret'),
                'app_id' => $sandbox ? self::SANDBOX_APP_ID : (string) config('settings.paypal_api_app_id'),
            ],
            'payment_action' => 'Sale',
            'currency' => (string) config('settings.currency_code'),
            'notify_url' => '',
            'locale' => get_lang(),
            'validate_ssl' => ! $sandbox,
        ]);
        $client->getAccessToken();

        return $client;
    }
}
