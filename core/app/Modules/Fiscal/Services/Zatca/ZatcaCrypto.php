<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Zatca;

/**
 * TAX-RUN Z-P2: ZATCA cryptographic primitives.
 *
 * ZATCA requires an ECDSA keypair on the secp256k1 curve, a CSR carrying KSA
 * EGS attributes, and a cryptographic stamp (ECDSA-SHA256) over the invoice on
 * simplified documents. We use PHP's openssl (secp256k1 is supported natively —
 * see openssl_get_curve_names()). Production HSM/token signing is a deferred
 * seam; the soft-key path here is what the sandbox/compliance flow uses.
 */
class ZatcaCrypto
{
    public const CURVE = 'secp256k1';

    /** The module's own OpenSSL config (req + v3_req sections). */
    public static function config(): string
    {
        return __DIR__.'/openssl-zatca.cnf';
    }

    /**
     * @return array{private:string,public:string} PEM-encoded keypair.
     */
    public function generateKeyPair(): array
    {
        $res = @openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => self::CURVE,
            'config' => self::config(),
        ]);
        if ($res === false) {
            // P4-01: some PHP builds (static macOS builds, e.g. Herd's 8.4) refuse private_key_type EC ("Private key
            // length must be at least 384 bits, configured to 0"); the `ec` parameter form generates the same key.
            $res = openssl_pkey_new(['ec' => ['curve_name' => self::CURVE]]);
        }

        if ($res === false) {
            throw new \RuntimeException('ZATCA keypair generation failed: '.openssl_error_string());
        }

        openssl_pkey_export($res, $privatePem, null, ['config' => self::config()]);
        $details = openssl_pkey_get_details($res);

        return ['private' => $privatePem, 'public' => $details['key']];
    }

    /**
     * Generate a CSR carrying the EGS attributes ZATCA mandates. `$egs` supplies
     * common_name, serial (1-<solution>|2-<model>|3-<serial>), organization_unit
     * (VAT for the taxpayer), organization, country (SA), invoice_type,
     * location, industry.
     *
     * @param  array<string,string>  $egs
     */
    public function generateCsr(string $privatePem, array $egs): string
    {
        $key = openssl_pkey_get_private($privatePem);
        if ($key === false) {
            throw new \RuntimeException('Invalid private key for CSR.');
        }

        $vat = (string) ($egs['vat'] ?? '');
        // subject in the SDK's order: C, OU, O, CN
        $dn = [
            'countryName' => $egs['country'] ?? 'SA',
            'organizationalUnitName' => $egs['organization_unit'] ?? ($vat !== '' ? $vat : 'EGS'),
            'organizationName' => $egs['organization'] ?? 'Restaurant',
            'commonName' => $egs['common_name'] ?? 'EGS',
        ];

        // P6-186 (J47 sandbox gate): FATOORA answers HTTP 400 to a CSR without its two extensions — the certificate
        // template name (1.3.6.1.4.1.311.20.2: TSTZATCA- / PREZATCA- / ZATCA-Code-Signing per environment) and the
        // subjectAltName dirName with SN (1-solution|2-model|3-serial), UID (VAT), title (invoice types),
        // registeredAddress and businessCategory. Built in a per-call config (never the host's openssl.cnf).
        // tmpfile(): created exclusively (0600) and removed on fclose — no predictable temp name (security preset).
        $fh = tmpfile();
        if ($fh === false) {
            throw new \RuntimeException('ZATCA CSR generation failed: no temporary config file.');
        }
        try {
            fwrite($fh, self::csrConfig($egs, $vat));
            fflush($fh);
            $csr = openssl_csr_new($dn, $key, [
                'digest_alg' => 'sha256',
                'req_extensions' => 'v3_req',
                'config' => stream_get_meta_data($fh)['uri'],
            ]);
        } finally {
            fclose($fh);
        }

        if ($csr === false) {
            throw new \RuntimeException('ZATCA CSR generation failed: '.openssl_error_string());
        }

        openssl_csr_export($csr, $csrPem);

        return $csrPem;
    }

    /** P6-186: the certificate template name ZATCA expects per FATOORA environment. */
    public static function certificateTemplate(?string $env = null): string
    {
        // outside a booted app (the host-config probe script) there is no config: sandbox
        $env ??= function_exists('app') && app()->bound('config') ? (string) config('services.zatca.environment', 'sandbox') : 'sandbox';

        return match ($env) {
            'production' => 'ZATCA-Code-Signing',
            'simulation' => 'PREZATCA-Code-Signing',
            default => 'TSTZATCA-Code-Signing',
        };
    }

    /**
     * P6-186: OpenSSL config for one ZATCA CSR (template name + subjectAltName dirName).
     *
     * @param  array<string,string>  $egs
     */
    public static function csrConfig(array $egs, string $vat): string
    {
        $clean = fn (?string $v, string $default): string => ($v = trim((string) preg_replace('~[\r\n=/\\\\\[\]]+~', ' ', (string) $v))) !== '' ? $v : $default;
        $serial = $clean($egs['serial'] ?? null, '1-Juwri|2-POS|3-'.substr(hash('sha256', ($egs['common_name'] ?? 'EGS').$vat), 0, 12));

        return implode("\n", [
            '[ req ]', 'distinguished_name = req_distinguished_name', 'req_extensions = v3_req', 'prompt = no', '',
            '[ req_distinguished_name ]', '',
            '[ v3_req ]',
            '1.3.6.1.4.1.311.20.2 = ASN1:PRINTABLESTRING:'.self::certificateTemplate(),
            'subjectAltName = dirName:zatca_alt',
            '',
            '[ zatca_alt ]',
            'SN = '.$serial,
            'UID = '.$clean($vat, '300000000000003'),
            'title = '.$clean($egs['invoice_type'] ?? null, '1100'),
            'registeredAddress = '.$clean($egs['location'] ?? null, 'Riyadh'),
            'businessCategory = '.$clean($egs['industry'] ?? null, 'Restaurant'),
            '',
        ]);
    }

    /**
     * ECDSA-SHA256 stamp over the canonicalized invoice. Returns base64 signature.
     */
    public function sign(string $data, string $privatePem): string
    {
        $key = openssl_pkey_get_private($privatePem);
        if ($key === false) {
            throw new \RuntimeException('Invalid private key for signing.');
        }

        $signature = '';
        if (! openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('ZATCA stamp signing failed: '.openssl_error_string());
        }

        return base64_encode($signature);
    }

    public function verify(string $data, string $signatureB64, string $publicPem): bool
    {
        $sig = base64_decode($signatureB64, true);
        if ($sig === false) {
            return false;
        }

        return openssl_verify($data, $sig, $publicPem, OPENSSL_ALGO_SHA256) === 1;
    }

    /** Base64 DER of the public key (TLV tag 8 content). */
    public function publicKeyDer(string $publicPem): string
    {
        $body = preg_replace('/-----(BEGIN|END) PUBLIC KEY-----|\s+/', '', $publicPem);

        return (string) $body;
    }
}
