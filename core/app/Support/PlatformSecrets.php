<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Juwri\Core\Support\SecretMask;

/**
 * Platform-wide secrets kept in the `options` table, encrypted at rest.
 *
 * The single list of which option names are credentials lives here. Every
 * read and write of those names goes through this class:
 *   - PlatformSettings::set()          encrypts on write (and ignores masks)
 *   - PlatformSettings::get()          decrypts on read
 *   - SettingsServiceProvider          decrypts into config('settings.*')
 *   - ViewServiceProvider              masks them in the shared $settings
 *
 * Per-store gateway secrets are a separate mechanism: they live in the
 * encrypted Credential vault (Juwri\Modules\Payments\Services\GatewaySecretVault).
 *
 * Values are encrypted with Laravel's Crypt (APP_KEY). Rotating APP_KEY
 * without re-encrypting makes every stored value unreadable — see decrypt().
 */
final class PlatformSecrets
{
    /** Exact `options.option_name` values that hold credentials. */
    public const KEYS = [
        // Plan-billing payment gateways (Admin → Payment gateways).
        'stripe_secret_key',
        'stripe_webhook_secret',
        'paypal_api_secret',
        'paypal_api_password',
        'paypal_api_signature',
        'paystack_secret_key',
        'paytabs_secret_key',
        'telr_authkey',
        'checkout_private_key',
        'mollie_api_key',
        'razorpay_secret_key',
        'razorpay_webhook_secret',
        'payumoney_signature_key',
        'payumoney_oauth_client_secret',
        'iyzico_api_key',
        'iyzico_secret_key',
        'midtrans_server_key',
        'flutterwave_secret_key',
        'flutterwave_secret_hash',
        'gocardless_access_token',
        'gocardless_webhook_secret',
        'lemon_squeezy_api_key',
        'lemon_squeezy_webhook_secret',
        'paddle_api_key',
        'paddle_webhook_secret',
        'paylink_secret_key',
        'paylink_partner_secret',
        'paymob_secret_key',
        'paymob_hmac_secret',
        'geidea_api_password',
        'tamara_api_token',
        'tamara_notification_token',
        'tabby_secret_key',
        'fawry_security_key',
        'fatora_api_key',
        'noon_api_key',
        'noon_webhook_secret',
        'ecash_api_key',
        'qrpay_secret_id',
        'tap_secret_key',
        'moyasar_secret_key', // Phase 4 item 18
        'moyasar_webhook_secret',
        'tdsp_token',
        'CCAVENUE_WORKING_KEY',
        // Mail, storage, sign-in, bot protection.
        'smtp_password',
        'sendgrid_pass',
        'mandrill_key',
        'aws_secret_key',
        'facebook_app_secret',
        'google_app_secret',
        'recaptcha_private_key',
        'abuse_turnstile_secret_key',
        // juwri-hosted services: this installation's request-signing secret (HostedServiceClient).
        'juwri_services_install_secret',
    ];

    public static function isSecret(?string $optionName): bool
    {
        return $optionName !== null && in_array($optionName, self::KEYS, true);
    }

    public static function encrypt(string $plain): string
    {
        return Crypt::encryptString($plain);
    }

    /** True when $stored is a payload this app's APP_KEY can decrypt. */
    public static function isEncrypted(mixed $stored): bool
    {
        if (! is_string($stored) || $stored === '') {
            return false;
        }
        try {
            Crypt::decryptString($stored);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }

    /**
     * Plaintext for a stored value. A value that is not a valid payload is
     * returned unchanged, so plaintext rows written before `secrets:encrypt`
     * ran keep working. A value that LOOKS like a Laravel payload but fails to
     * decrypt means APP_KEY changed: it is logged and returned as-is.
     */
    public static function decrypt(mixed $stored): mixed
    {
        if (! is_string($stored) || $stored === '') {
            return $stored;
        }
        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            if (self::looksLikePayload($stored)) {
                Log::warning('A platform secret could not be decrypted; APP_KEY may have changed since it was saved.');
            }

            return $stored;
        }
    }

    /**
     * Decrypt the secret entries of an option_name => option_value map.
     *
     * @template T of \Illuminate\Support\Collection<array-key, mixed>|array<array-key, mixed>
     *
     * @param  T  $settings
     * @return T
     */
    public static function decryptMap(Collection|array $settings): Collection|array
    {
        // Never mutate the caller's collection: it may be the very object a
        // cache store holds, which must keep the encrypted values.
        if ($settings instanceof Collection) {
            $settings = clone $settings;
        }
        foreach (self::KEYS as $key) {
            if (isset($settings[$key])) {
                $settings[$key] = self::decrypt($settings[$key]);
            }
        }

        return $settings;
    }

    /**
     * Replace the secret entries of an option_name => option_value map with
     * their display mask (for views; runtime code reads config('settings.*')).
     *
     * @template T of \Illuminate\Support\Collection<array-key, mixed>|array<array-key, mixed>
     *
     * @param  T  $settings
     * @return T
     */
    public static function maskMap(Collection|array $settings): Collection|array
    {
        // Never mutate the caller's collection: it may be the very object a
        // cache store holds, which must keep the encrypted values.
        if ($settings instanceof Collection) {
            $settings = clone $settings;
        }
        foreach (self::KEYS as $key) {
            if (isset($settings[$key]) && is_string($settings[$key]) && $settings[$key] !== '') {
                $plain = self::decrypt($settings[$key]);
                $settings[$key] = SecretMask::mask(is_string($plain) ? $plain : '');
            }
        }

        return $settings;
    }

    /** Laravel payloads are base64 JSON with iv / value / mac keys. */
    private static function looksLikePayload(string $value): bool
    {
        $json = base64_decode($value, true);
        if ($json === false) {
            return false;
        }
        $data = json_decode($json, true);

        return is_array($data) && isset($data['iv'], $data['value'], $data['mac']);
    }
}
