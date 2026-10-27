<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Fiscal;

use Juwri\Modules\Fiscal\Services\Zatca\ZatcaCrypto;
use PHPUnit\Framework\TestCase;

/**
 * P4-01 (found by the fiscal journey on a macOS PHP 8.4 build): ZATCA onboarding failed before the OTP was ever sent —
 * the CSR used the HOST's openssl.cnf, which on macOS has no [v3_req] section ("Error loading
 * request_extensions_section section v3_req"), and that build also refuses `private_key_type` EC. ZatcaCrypto now
 * passes its own config and falls back to the `ec` parameter form. Proven in a child PHP whose OPENSSL_CONF has no
 * [v3_req] section (the host condition).
 */
class ZatcaCryptoHostConfigTest extends TestCase
{
    public function test_keypair_csr_and_signature_do_not_depend_on_the_hosts_openssl_cnf(): void
    {
        $dir = sys_get_temp_dir().'/zatca-cnf-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $bare = $dir.'/openssl.cnf';
        file_put_contents($bare, "[ req ]\ndistinguished_name = dn\n[ dn ]\n"); // like macOS: no [v3_req]
        $script = $dir.'/run.php';
        file_put_contents($script, '<?php require '.var_export(dirname(__DIR__, 3).'/vendor/autoload.php', true).';
            $c = new '.ZatcaCrypto::class.';
            $k = $c->generateKeyPair();
            $csr = $c->generateCsr($k["private"], ["common_name" => "EGS-1", "vat" => "300000000000003", "country" => "SA"]);
            $sig = $c->sign("payload", $k["private"]);
            echo str_contains($csr, "BEGIN CERTIFICATE REQUEST") && $sig !== "" ? "OK" : "BAD";');

        $out = [];
        exec('OPENSSL_CONF='.escapeshellarg($bare).' '.escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' 2>&1', $out, $code);
        @unlink($script);
        @unlink($bare);
        @rmdir($dir);

        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertSame('OK', trim((string) end($out)), implode("\n", $out));
    }

    public function test_the_module_config_has_the_request_extensions_section(): void
    {
        $cnf = (string) file_get_contents(ZatcaCrypto::config());
        $this->assertStringContainsString('[ v3_req ]', $cnf);
        $this->assertStringContainsString('req_extensions = v3_req', $cnf);
    }
}
