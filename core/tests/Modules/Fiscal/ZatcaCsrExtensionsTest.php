<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Fiscal;

use Juwri\Modules\Fiscal\Services\Zatca\ZatcaCrypto;
use Tests\TestCase;

/**
 * P6-186 (J47 sandbox gate): FATOORA's developer portal answered "400 Invalid Request" to our CSR. It accepts a CSR
 * with the SDK's shape: subject C, OU, O, CN; the certificate template extension per environment; the subjectAltName
 * dirName (SN, UID = VAT, title, registeredAddress, businessCategory); no other extensions.
 */
class ZatcaCsrExtensionsTest extends TestCase
{
    public function test_csr_has_the_zatca_shape(): void
    {
        config(['services.zatca.environment' => 'sandbox']);
        $c = new ZatcaCrypto;
        $k = $c->generateKeyPair();
        $pem = $c->generateCsr($k['private'], ['common_name' => 'TST-1-399999999900003', 'organization' => 'Diner', 'organization_unit' => 'Riyadh',
            'vat' => '399999999900003', 'serial' => '1-Juwri|2-POS|3-abc', 'invoice_type' => '1100', 'location' => 'Riyadh', 'industry' => 'Food']);
        $der = base64_decode((string) preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem));

        $this->assertSame(['C' => 'SA', 'OU' => 'Riyadh', 'O' => 'Diner', 'CN' => 'TST-1-399999999900003'], openssl_csr_get_subject($pem, true));
        $this->assertStringContainsString('TSTZATCA-Code-Signing', $der);
        foreach (['1-Juwri|2-POS|3-abc', '399999999900003', '1100', 'Riyadh', 'Food'] as $v) {
            $this->assertStringContainsString($v, $der);
        }
        $this->assertStringNotContainsString("\x55\x1d\x13", $der, 'no basicConstraints'); // OID 2.5.29.19
        $this->assertStringNotContainsString("\x55\x1d\x0f", $der, 'no keyUsage');         // OID 2.5.29.15
    }

    public function test_template_per_environment(): void
    {
        $this->assertSame('TSTZATCA-Code-Signing', ZatcaCrypto::certificateTemplate('sandbox'));
        $this->assertSame('PREZATCA-Code-Signing', ZatcaCrypto::certificateTemplate('simulation'));
        $this->assertSame('ZATCA-Code-Signing', ZatcaCrypto::certificateTemplate('production'));
    }

    public function test_config_values_cannot_inject_sections(): void
    {
        $cnf = ZatcaCrypto::csrConfig(['industry' => "Food\n[ v3_req ]\nbasicConstraints=CA:TRUE"], '399999999900003');
        $lines = explode("\n", $cnf);
        $this->assertSame(1, count(array_filter($lines, fn ($l) => trim($l) === '[ v3_req ]')));
        $this->assertSame([], array_values(array_filter($lines, fn ($l) => str_starts_with(trim($l), 'basicConstraints'))));
    }
}
