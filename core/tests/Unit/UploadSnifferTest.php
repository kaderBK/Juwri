<?php

namespace Tests\Unit;

use App\Core\Support\UploadSniffer;
use PHPUnit\Framework\TestCase;

class UploadSnifferTest extends TestCase
{
    public function test_font_accepts_only_real_woff2(): void
    {
        $this->assertSame('woff2', UploadSniffer::font('wOF2'.str_repeat("\0", 8)));
        $this->assertNull(UploadSniffer::font('<?php'));
        $this->assertNull(UploadSniffer::font('<html>'));
        $this->assertNull(UploadSniffer::font('<svg '));
        $this->assertNull(UploadSniffer::font('wOFF'));
    }

    public function test_licence_extension_comes_from_content(): void
    {
        $dir = sys_get_temp_dir();
        $php = tempnam($dir, 'u');
        file_put_contents($php, '<?php system($_GET[0]); ?>');
        $this->assertNull(UploadSniffer::licenceExtension($php));
        $html = tempnam($dir, 'u');
        file_put_contents($html, '<html><script>alert(1)</script></html>');
        $this->assertNull(UploadSniffer::licenceExtension($html));
        $svg = tempnam($dir, 'u');
        file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"><script>1</script></svg>');
        $this->assertNull(UploadSniffer::licenceExtension($svg));
        $pdf = tempnam($dir, 'u');
        file_put_contents($pdf, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        $this->assertSame('pdf', UploadSniffer::licenceExtension($pdf));
        $png = tempnam($dir, 'u');
        file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
        $this->assertSame('png', UploadSniffer::licenceExtension($png));
        array_map('unlink', [$php, $html, $svg, $pdf, $png]);
    }

    public function test_spreadsheet_and_csv_signatures(): void
    {
        $this->assertTrue(UploadSniffer::isZip("PK\x03\x04rest"));
        $this->assertFalse(UploadSniffer::isZip('<?php'));
        $this->assertFalse(UploadSniffer::isZip('<html>'));
        $this->assertTrue(UploadSniffer::isPlainText("name,price\nTea,3\n"));
        $this->assertFalse(UploadSniffer::isPlainText("name\0price"));
        $this->assertFalse(UploadSniffer::isPlainText("PK\x03\x04abc"));
        $this->assertFalse(UploadSniffer::isPlainText('%PDF-1.4'));
    }

    public function test_caddy_and_htaccess_block_active_uploads(): void
    {
        $caddy = (string) file_get_contents(__DIR__.'/../../../docker/Caddyfile');
        $this->assertStringContainsString('@storageActive', $caddy);
        $this->assertStringContainsString('phtml', $caddy);
        $ht = (string) file_get_contents(__DIR__.'/../../../.htaccess');
        $this->assertStringContainsString('^storage/.*\.(php', $ht);
    }
}
