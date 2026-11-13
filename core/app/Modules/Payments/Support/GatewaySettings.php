<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Payments\Support;

/**
 * The platform settings (options table) each payment gateway's admin form
 * edits, by gateway folder. Saving a gateway writes exactly these keys: a
 * listed key the form leaves out is cleared, anything else posted is ignored.
 * Secret keys are encrypted by PlatformSettings::set (PlatformSecrets).
 */
final class GatewaySettings
{
    /** @var array<string, list<string>> */
    private const KEYS = [
        'paypal' => [
            'paypal_sandbox_mode',
            'paypal_payment_mode',
            'paypal_api_client_id',
            'paypal_api_secret',
            'paypal_api_app_id',
            // WI-06a/WI-10f: when set, notifications must pass PayPal's signature check.
            'paypal_webhook_id',
        ],
        'stripe' => [
            'stripe_payment_mode',
            'stripe_publishable_key',
            'stripe_secret_key',
            'stripe_webhook_secret',
        ],
        'wire_transfer' => [
            'company_bank_info',
        ],
        'two_checkout' => [
            '2checkout_sandbox_mode',
            'checkout_account_number',
            'checkout_public_key',
            'checkout_private_key',
        ],
        'paystack' => [
            'paystack_secret_key',
            'paystack_public_key',
        ],
        'payumoney' => [
            'payumoney_sandbox_mode',
            'payumoney_merchant_pos_id',
            'payumoney_signature_key',
            'payumoney_oauth_client_id',
            'payumoney_oauth_client_secret',
        ],
        'mollie' => [
            'mollie_api_key',
            'mollie_webhook_enabled',
            'mollie_webhook_url',
            // H4: mollie_webhook_last_at / _last_status are written by the webhook, NEVER by
            // this form (the update loop blanks any listed key a save doesn't carry) — do NOT list them.
        ],
        'razorpay' => [
            'razorpay_api_key',
            'razorpay_secret_key',
            // WI-06a/WI-10f: signs webhook/razorpay (secret, see PlatformSecrets).
            'razorpay_webhook_secret',
        ],
        'paytm' => [
            'PAYTM_ENVIRONMENT',
            'PAYTM_MERCHANT_KEY',
            'PAYTM_MERCHANT_MID',
            'PAYTM_MERCHANT_WEBSITE',
        ],
        'ccavenue' => [
            'CCAVENUE_MERCHANT_KEY',
            'CCAVENUE_ACCESS_CODE',
            'CCAVENUE_WORKING_KEY',
        ],
        'paytabs' => [
            'paytabs_region',
            'paytabs_profile_id',
            'paytabs_secret_key',
        ],
        'telr' => [
            'telr_sandbox_mode',
            'telr_store_id',
            'telr_authkey',
        ],
        'midtrans' => [
            'midtrans_sandbox_mode',
            'midtrans_client_key',
            'midtrans_server_key',
        ],
        'iyzico' => [
            'iyzico_sandbox_mode',
            'iyzico_api_key',
            'iyzico_secret_key',
        ],
        // MoR-RUN: Paddle Billing (Classic keys vendor_id/public_key retired)
        'paddle' => [
            'paddle_sandbox_mode',
            'paddle_api_key',
            'paddle_webhook_secret',
        ],
        // MoR-RUN: Lemon Squeezy (merchant of record)
        'lemon_squeezy' => [
            'lemon_squeezy_test_mode',
            'lemon_squeezy_api_key',
            'lemon_squeezy_store_id',
            'lemon_squeezy_variant_id',
            'lemon_squeezy_variant_id_onetime',
            'lemon_squeezy_webhook_secret',
        ],
        'tdsp' => [
            'tdsp_sandbox',
            'tdsp_store_id',
            'tdsp_token',
        ],
        // PAYMENTS-RUN gateways (platform / plan-context creds)
        'paylink' => [
            'paylink_sandbox',
            'paylink_api_id',
            'paylink_secret_key',
            // G10: platform-level PARTNER API credentials (superadmin only).
            'paylink_partner_sandbox',
            'paylink_partner_api_id',
            'paylink_partner_secret',
        ],
        'paymob' => [
            'paymob_sandbox',
            'paymob_secret_key',
            'paymob_public_key',
            'paymob_integration_id',
            'paymob_hmac_secret',
            'paymob_region',
        ],
        'geidea' => [
            'geidea_sandbox',
            'geidea_merchant_public_key',
            'geidea_api_password',
        ],
        'tamara' => [
            'tamara_sandbox',
            'tamara_api_token',
            'tamara_notification_token',
        ],
        'tabby' => [
            'tabby_sandbox',
            'tabby_secret_key',
            'tabby_public_key',
            'tabby_merchant_code',
        ],
        'fawry' => [
            'fawry_sandbox',
            'fawry_merchant_code',
            'fawry_security_key',
        ],
        'fatora' => [
            'fatora_sandbox',
            'fatora_api_key',
        ],
        // Syria gateways (platform / plan-context creds). Direct acquirers
        // (NOT merchant-of-record) — offered on normal platform billing.
        'ecash' => [
            'ecash_sandbox',
            'ecash_merchant_id',
            'ecash_terminal_id',
            'ecash_api_key',
        ],
        'qrpay' => [
            'qrpay_sandbox',
            'qrpay_client_id',
            'qrpay_secret_id',
        ],
        // Phase 4 item 18: Tap (MENA) and Moyasar (KSA) — a test key (sk_test_…) only while payment sandboxes are allowed.
        // Tap signs its charge POST with the secret key (no separate webhook secret); Moyasar sends the webhook's secret token.
        'tap' => [
            'tap_secret_key',
            'tap_publishable_key',
        ],
        'moyasar' => [
            'moyasar_secret_key',
            'moyasar_publishable_key',
            'moyasar_webhook_secret',
        ],
        // Bug fix (Phase 4 item 18): Flutterwave had a gateway row but no admin form (edit page 500).
        'flutterwave' => [
            'flutterwave_secret_key',
            'flutterwave_secret_hash',
        ],
        'noon' => [
            'noon_sandbox',
            'noon_business_id',
            'noon_application_id',
            'noon_api_key',
            // WIRING-RUN E2: verifies noon's HMAC-SHA512 webhook signature.
            'noon_webhook_secret',
        ],
        'gocardless' => [
            'gocardless_sandbox',
            'gocardless_access_token',
            'gocardless_webhook_secret',
        ],
    ];

    /** @return list<string> every gateway setting key (other screens must not write them) */
    public static function allKeys(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::KEYS))));
    }

    /** @return list<string> */
    public static function keysFor(?string $folder): array
    {
        return self::KEYS[(string) $folder] ?? [];
    }
}
