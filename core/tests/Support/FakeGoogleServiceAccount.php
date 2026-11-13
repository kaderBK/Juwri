<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support;

/**
 * A Google service-account JSON file for the Wallet tests, with a key pair generated at run time.
 * No private key is committed: one RSA key per PHP process, written to a temp file that is removed at shutdown.
 */
final class FakeGoogleServiceAccount
{
    private static ?string $path = null;

    public static function path(): string
    {
        if (self::$path !== null && is_file(self::$path)) {
            return self::$path;
        }

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            throw new \RuntimeException('openssl_pkey_new failed: '.openssl_error_string());
        }
        openssl_pkey_export($key, $pem);

        $path = tempnam(sys_get_temp_dir(), 'fake-sa-');
        file_put_contents($path, json_encode([
            'type' => 'service_account',
            'project_id' => 'juwri-wallet-test-DO-NOT-USE',
            'private_key_id' => str_repeat('0', 40),
            'private_key' => $pem,
            'client_email' => 'wallet-test@juwri-wallet-test-DO-NOT-USE.iam.gserviceaccount.com',
            'client_id' => str_repeat('0', 21),
            'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
            'token_uri' => 'https://oauth2.googleapis.com/token',
            '_comment' => 'Generated at test time. Not a real credential.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        chmod($path, 0600);
        register_shutdown_function(static function () use ($path): void {
            @unlink($path);
        });

        return self::$path = $path;
    }
}
