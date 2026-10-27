<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support\InApp;

use Firebase\JWT\JWT;

/**
 * P5-17 tests: an Apple-shaped certificate chain (EC root → intermediate with Apple's WWDR OID → leaf with the App Store
 * receipt-signing OID) and ES256 JWS signed with it, exactly as the App Store signs transactions and notifications.
 * The test pins this root's SHA-256 in config, so the verifier runs its real checks.
 */
final class FakeAppleSigner
{
    /** @var array{key: \OpenSSLAsymmetricKey, pem: string} */
    public array $root;

    /** @var array{key: \OpenSSLAsymmetricKey, pem: string} */
    public array $intermediate;

    /** @var array{key: \OpenSSLAsymmetricKey, pem: string} */
    public array $leaf;

    private string $conf;

    public function __construct(bool $leafOid = true)
    {
        $this->conf = tempnam(sys_get_temp_dir(), 'apple-ssl');
        file_put_contents($this->conf, implode("\n", [
            '[req]', 'distinguished_name = dn', '[dn]',
            '[v3_ca]', 'basicConstraints = critical,CA:true', 'keyUsage = critical,keyCertSign,cRLSign',
            '[v3_int]', 'basicConstraints = critical,CA:true', 'keyUsage = critical,keyCertSign,cRLSign', '1.2.840.113635.100.6.2.1 = ASN1:NULL',
            '[v3_leaf]', 'basicConstraints = critical,CA:false', 'keyUsage = critical,digitalSignature', '1.2.840.113635.100.6.11.1 = ASN1:NULL',
            '[v3_leaf_plain]', 'basicConstraints = critical,CA:false', 'keyUsage = critical,digitalSignature',
        ])."\n");
        $this->root = $this->cert('Test Apple Root', null, 'v3_ca');
        $this->intermediate = $this->cert('Test WWDR', $this->root, 'v3_int');
        $this->leaf = $this->cert('Test Prod ECC Mac App Store', $this->intermediate, $leafOid ? 'v3_leaf' : 'v3_leaf_plain');
    }

    public function rootSha256(): string
    {
        return hash('sha256', self::der($this->root['pem']));
    }

    /** @param  array<string, mixed>  $payload */
    public function sign(array $payload, ?array $chain = null, ?\OpenSSLAsymmetricKey $key = null): string
    {
        $chain ??= [$this->leaf, $this->intermediate, $this->root];
        openssl_pkey_export($key ?? $this->leaf['key'], $pem, null, ['config' => $this->conf]);

        return JWT::encode($payload, $pem, 'ES256', null, ['x5c' => array_map(fn ($c) => base64_encode(self::der($c['pem'])), $chain)]);
    }

    /** @return array{key: \OpenSSLAsymmetricKey, pem: string} */
    private function cert(string $cn, ?array $issuer, string $ext): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'config' => $this->conf])
            ?: openssl_pkey_new(['ec' => ['curve_name' => 'prime256v1']]);
        $csr = openssl_csr_new(['commonName' => $cn], $key, ['digest_alg' => 'sha256', 'config' => $this->conf]);
        $x = openssl_csr_sign($csr, $issuer['pem'] ?? null, $issuer['key'] ?? $key, 30, ['digest_alg' => 'sha256', 'config' => $this->conf, 'x509_extensions' => $ext], random_int(1, PHP_INT_MAX));
        openssl_x509_export($x, $pem);

        return ['key' => $key, 'pem' => $pem];
    }

    private static function der(string $pem): string
    {
        return (string) base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', $pem));
    }
}
