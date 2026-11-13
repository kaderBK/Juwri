<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Fiscal;

use Illuminate\Support\Facades\Artisan;
use Juwri\Modules\Fiscal\Services\Zatca\Sdk\JavaDetector;
use Juwri\Modules\Fiscal\Services\Zatca\Sdk\SdkOutputParser;
use Juwri\Modules\Fiscal\Services\Zatca\Sdk\SdkSampleSet;
use Tests\TestCase;

/**
 * P6-150: ZATCA SDK validate wrapper. No real SDK, no Java, no network: a fake SDK folder prints canned output.
 */
class ZatcaSdkValidateTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/zatca-sdk-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/sdk/Apps', 0777, true);
        mkdir($this->dir.'/bin', 0777, true);
        // Fake Java 11 (prints to stderr like the real one).
        file_put_contents($this->dir.'/bin/java', "#!/bin/sh\necho 'openjdk version \"11.0.21\" 2023-10-17' >&2\n");
        chmod($this->dir.'/bin/java', 0755);
        // Fake SDK: passes everything except the credit note, and tries to leak a key line that must be redacted.
        file_put_contents($this->dir.'/sdk/Apps/fatoora', <<<'SH'
#!/bin/bash
f="$3"
case "$f" in
  *credit-note*)
    echo "INFO  - [XSD] validation result : PASSED"
    echo "ERROR - [KSA] BR-KSA-26: The invoice hash is wrong"
    echo "WARNING - [KSA] BR-KSA-98: certificate is not issued by ZATCA"
    echo "-----BEGIN EC PRIVATE KEY-----"
    echo "MHQCAQEEIGsecretsecretsecret"
    echo "-----END EC PRIVATE KEY-----"
    echo "INFO  - ### Global validation result : FAILED"
    ;;
  *) echo "INFO  - ### Global validation result : PASSED" ;;
esac
SH);
    }

    protected function tearDown(): void
    {
        foreach ([$this->dir.'/sdk/Apps/fatoora', $this->dir.'/bin/java'] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir.'/sdk/Apps');
        @rmdir($this->dir.'/sdk');
        @rmdir($this->dir.'/bin');
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_java_version_parsing(): void
    {
        $this->assertSame(8, JavaDetector::parse('java version "1.8.0_352"')['major']);
        $this->assertSame('FAIL', JavaDetector::parse('java version "1.8.0_352"')['level']);
        $this->assertSame('OK', JavaDetector::parse('openjdk version "11.0.21" 2023-10-17')['level']);
        $this->assertSame('OK', JavaDetector::parse('openjdk version "14" 2020-03-17')['level']);
        $w = JavaDetector::parse('openjdk version "21.0.1" 2023-10-17');
        $this->assertSame('WARN', $w['level']);
        $this->assertTrue($w['ok']);
        $none = JavaDetector::parse('');
        $this->assertFalse($none['found']);
        $this->assertFalse($none['ok']);
        $this->assertStringContainsString('Install Java 11 to 14', $none['advice']);
    }

    public function test_missing_java_binary_is_reported_not_thrown(): void
    {
        $r = JavaDetector::detect($this->dir.'/no-such-java');
        $this->assertFalse($r['found']);
        $this->assertSame('FAIL', $r['level']);
    }

    public function test_output_parser_pass_fail_and_redaction(): void
    {
        $pass = SdkOutputParser::parse("INFO - [XSD] validation result : PASSED\nINFO - ### Global validation result : PASSED\n");
        $this->assertSame('PASS', $pass['status']);
        $this->assertSame([], $pass['messages']);

        $fail = SdkOutputParser::parse("\e[31mERROR\e[0m - [KSA] BR-KSA-26 bad hash\n-----BEGIN EC PRIVATE KEY-----\nMHQsecret\n-----END EC PRIVATE KEY-----\nINFO - ### Global validation result : FAILED\n");
        $this->assertSame('FAIL', $fail['status']);
        $this->assertStringContainsString('BR-KSA-26', implode("\n", $fail['messages']));
        $this->assertStringNotContainsString('MHQsecret', implode("\n", $fail['messages']));

        $unknown = SdkOutputParser::parse("Exception in thread main java.lang.Error\n");
        $this->assertSame('UNKNOWN', $unknown['status']);
        $this->assertNotSame([], $unknown['messages']);
    }

    public function test_sample_set_comes_from_the_real_builder(): void
    {
        $samples = (new SdkSampleSet)->build();
        $this->assertSame(['simplified-invoice', 'standard-invoice', 'standard-credit-note', 'simplified-levy', 'simplified-discount', 'standard-zero-exempt',
            'vat-line-simplified', 'vat-line-standard', 'vat-line-credit-note', 'vat-line-debit-note', // P6-186: both VAT modes
            'vat-line-simplified-credit-note', 'vat-line-simplified-debit-note', 'vat-line-simplified-mixed',
            'vat-order-simplified', 'vat-order-standard', 'vat-order-credit-note', 'vat-order-debit-note',
            'vat-order-simplified-credit-note', 'vat-order-simplified-debit-note', 'vat-order-simplified-mixed'], array_column($samples, 'name'));
        $codes = [];
        foreach ($samples as $s) {
            $doc = new \DOMDocument;
            $this->assertTrue($doc->loadXML($s['xml']), $s['name'].' parses');
            $codes[$s['name']] = $doc->getElementsByTagName('InvoiceTypeCode')->item(0)->textContent.'/'.$doc->getElementsByTagName('InvoiceTypeCode')->item(0)->getAttribute('name');
            $this->assertStringNotContainsString('PRIVATE KEY', $s['xml']);
        }
        $this->assertSame('388/0200000', $codes['simplified-invoice']);
        $this->assertSame('388/0100000', $codes['standard-invoice']);
        $this->assertSame('381/0100000', $codes['standard-credit-note']);
        // P6-175: the levy is its own standard-rated line (VAT on top), Z/E lines carry their VATEX codes
        $this->assertMatchesRegularExpression('#<cbc:Name>Municipality fee</cbc:Name>\s*<cac:ClassifiedTaxCategory>\s*<cbc:ID>S</cbc:ID>#', $samples[3]['xml']);
        $this->assertStringContainsString('<cbc:ChargeIndicator>false</cbc:ChargeIndicator>', $samples[4]['xml']);
        $this->assertStringContainsString('<cbc:TaxExemptionReasonCode>VATEX-SA-35</cbc:TaxExemptionReasonCode>', $samples[5]['xml']);
        $this->assertStringContainsString('<cbc:TaxExemptionReasonCode>VATEX-SA-29</cbc:TaxExemptionReasonCode>', $samples[5]['xml']);
        $this->assertMatchesRegularExpression('#<cbc:ID[^>]*>QR</cbc:ID>#', $samples[0]['xml']);
        // P6-176: every sample names the seller with its national address; the standard ones (and their note) the full buyer
        foreach ($samples as $smp) {
            $d = new \DOMDocument;
            $d->loadXML($smp['xml']);
            $xp = new \DOMXPath($d);
            $xp->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
            $xp->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
            $this->assertSame('12211', $xp->evaluate('string(//cac:AccountingSupplierParty//cac:PostalAddress/cbc:PostalZone)'), $smp['name']);
            $this->assertSame('1010010000', $xp->evaluate('string(//cac:AccountingSupplierParty//cac:PartyIdentification/cbc:ID[@schemeID="CRN"])'), $smp['name']);
            $standard = str_starts_with($smp['name'], 'standard') || (str_starts_with($smp['name'], 'vat-') && ! str_contains($smp['name'], 'simplified')); // P6-186 mode samples
            $this->assertSame($standard ? '399999999800003' : '', $xp->evaluate('string(//cac:AccountingCustomerParty//cac:PartyTaxScheme/cbc:CompanyID)'), $smp['name']);
            $this->assertSame($standard ? '2322' : '', $xp->evaluate('string(//cac:AccountingCustomerParty//cbc:BuildingNumber)'), $smp['name']);
            $this->assertSame(1, (int) $xp->evaluate('count(/*/cac:AccountingCustomerParty)'), $smp['name']);
        }
    }

    public function test_command_prints_a_table_and_fails_when_the_sdk_fails_a_sample(): void
    {
        $code = Artisan::call('fiscal:zatca-sdk-validate', ['--sdk' => $this->dir.'/sdk', '--java' => $this->dir.'/bin/java']);
        $out = Artisan::output();
        $this->assertSame(1, $code);
        $this->assertStringContainsString('simplified-invoice', $out);
        $this->assertStringContainsString('PASS', $out);
        $this->assertStringContainsString('standard-credit-note', $out);
        $this->assertStringContainsString('FAIL', $out);
        $this->assertStringContainsString('BR-KSA-26', $out);
        $this->assertStringNotContainsString('secretsecret', $out);
        $this->assertStringNotContainsString('PRIVATE KEY', $out);
    }

    public function test_command_explains_missing_java_and_missing_sdk(): void
    {
        $this->assertSame(1, Artisan::call('fiscal:zatca-sdk-validate', ['--sdk' => $this->dir.'/sdk', '--java' => $this->dir.'/none']));
        $this->assertStringContainsString('Install Java 11 to 14', Artisan::output());

        $this->assertSame(1, Artisan::call('fiscal:zatca-sdk-validate', ['--sdk' => $this->dir.'/empty', '--java' => $this->dir.'/bin/java']));
        $this->assertStringContainsString('Apps/fatoora', Artisan::output());
    }
}
