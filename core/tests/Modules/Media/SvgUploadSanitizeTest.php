<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Media;

use App\Http\Middleware\Installed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Juwri\Core\Http\Middleware\ServePublicSvg;
use Juwri\Modules\Media\Services\StorageService;
use Tests\TestCase;

/**
 * P6-147: an uploaded SVG is sanitised before it reaches public storage, an unparseable one is
 * rejected (not written as an empty file), and the stored file is served with the sandbox CSP.
 */
class SvgUploadSanitizeTest extends TestCase
{
    use RefreshDatabase;

    private string $rel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
        $this->rel = 'storage/p6147-upload-'.uniqid().'/';
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(public_path($this->rel));
        parent::tearDown();
    }

    private function svgUpload(string $content): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'svg');
        file_put_contents($tmp, $content);

        return new UploadedFile($tmp, 'logo.svg', 'image/svg+xml', null, true);
    }

    public function test_upload_strips_script_and_is_served_sandboxed(): void
    {
        $name = StorageService::upload($this->svgUpload(
            '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(2)</script><rect width="5" height="5"/></svg>'
        ), $this->rel);

        $this->assertStringEndsWith('.svg', $name);
        $stored = (string) file_get_contents(public_path($this->rel).$name);
        $this->assertStringNotContainsStringIgnoringCase('<script', $stored);
        $this->assertStringNotContainsStringIgnoringCase('onload', $stored);
        $this->assertStringContainsString('<rect', $stored);

        $resp = $this->get('/'.$this->rel.$name);
        $resp->assertOk();
        $resp->assertHeader('Content-Security-Policy', ServePublicSvg::CSP);
    }

    public function test_unparseable_svg_is_rejected(): void
    {
        $name = StorageService::upload($this->svgUpload('<svg xmlns="http://www.w3.org/2000/svg"><rect'), $this->rel);

        $this->assertSame('', $name);
        $this->assertSame([], glob(public_path($this->rel).'*.svg') ?: []);
    }
}
