<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content\Studio;

use App\Support\HostCapabilities;
use Juwri\Modules\Content\Services\Studio\HeadlessChrome;
use Juwri\Modules\Content\Services\Studio\StudioTemplateExportService;
use RuntimeException;
use Tests\TestCase;

/**
 * J18 — spatie/browsershot replaced by HeadlessChrome (node + puppeteer via
 * headless-render.mjs). No Chrome in CI: a fake `node` (a PHP script) stands in
 * for the process, so the job contract, temp-file handling and failure path are
 * pinned; the export service keeps Browsershot's settings.
 */
class HeadlessChromeTest extends TestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    /** A fake node binary: records the job, writes "<mode>:<html>" to `out` (or fails). */
    private function fakeNode(bool $fail = false): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fake-node-');
        $log = $path.'.job';
        $this->cleanup[] = $path;
        $this->cleanup[] = $log;
        $body = $fail
            ? 'fwrite(STDERR, "chrome crashed"); exit(1);'
            : '$job = json_decode(file_get_contents($argv[2]), true);'
                .' file_put_contents('.var_export($log, true).', json_encode($job + ["script" => $argv[1]]));'
                .' file_put_contents($job["out"], $job["mode"].":".file_get_contents($job["html"]));';
        file_put_contents($path, '#!'.PHP_BINARY."\n<?php\n".$body."\n");
        chmod($path, 0755);

        return $path;
    }

    /** @return array<string, mixed> */
    private function recordedJob(string $node): array
    {
        return (array) json_decode((string) file_get_contents($node.'.job'), true);
    }

    public function test_pdf_runs_the_render_script_with_browsershot_equivalent_settings(): void
    {
        if (! (new HostCapabilities)->processesAllowed()) {
            $this->markTestSkipped('needs proc_open (disabled on this host)');
        }
        $node = $this->fakeNode();
        $chrome = new HeadlessChrome('/opt/chrome', $node);

        $this->assertSame('pdf:<p>menu</p>', $chrome->pdf('<p>menu</p>', 'Letter'));

        $job = $this->recordedJob($node);
        $this->assertStringEndsWith('app/Modules/Content/Services/Studio/headless-render.mjs', $job['script']);
        $this->assertSame('pdf', $job['mode']);
        $this->assertSame('Letter', $job['format']);
        $this->assertSame('/opt/chrome', $job['chromePath']);
        $this->assertTrue($job['noSandbox']);
        $this->assertSame(60000, $job['timeoutMs']);
        $this->assertDirectoryDoesNotExist(dirname($job['html']), 'temp files are removed');
    }

    public function test_screenshot_passes_viewport_scale_and_selector(): void
    {
        if (! (new HostCapabilities)->processesAllowed()) {
            $this->markTestSkipped('needs proc_open (disabled on this host)');
        }
        $node = $this->fakeNode();

        $this->assertSame('png:<div class="sheet"></div>', (new HeadlessChrome(null, $node))->screenshot('<div class="sheet"></div>', 681, 1210, 2.0, '.sheet'));

        $job = $this->recordedJob($node);
        $this->assertEquals(['width' => 681, 'height' => 1210, 'deviceScaleFactor' => 2], $job['viewport']);
        $this->assertSame('.sheet', $job['select']);
        $this->assertNull($job['chromePath']);
    }

    public function test_a_failed_render_throws_and_cleans_up(): void
    {
        if (! (new HostCapabilities)->processesAllowed()) {
            $this->markTestSkipped('needs proc_open (disabled on this host)');
        }
        $chrome = new HeadlessChrome(null, $this->fakeNode(fail: true));
        $before = glob(sys_get_temp_dir().'/juwri-chrome-*') ?: [];

        try {
            $chrome->pdf('<p>x</p>', 'A4');
            $this->fail('expected a RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('chrome crashed', $e->getMessage());
        }
        $this->assertSame($before, glob(sys_get_temp_dir().'/juwri-chrome-*') ?: []);
    }

    public function test_a_missing_node_binary_throws(): void
    {
        $this->expectException(RuntimeException::class);
        (new HeadlessChrome(null, '/nonexistent/node'))->pdf('<p>x</p>', 'A4');
    }

    public function test_export_service_keeps_the_print_and_social_settings(): void
    {
        $calls = [];
        $this->app->instance(HeadlessChrome::class, new class($calls) extends HeadlessChrome
        {
            /** @param  list<array<string, mixed>>  $calls */
            public function __construct(private array &$calls)
            {
                parent::__construct();
            }

            protected function run(string $html, array $job): string
            {
                $this->calls[] = $job;

                return 'bytes';
            }
        });
        $svc = new StudioTemplateExportService;

        $svc->toPdf('<p/>', 'a4');
        $svc->toPdf('<p/>', 'letter');
        $svc->toPng('<p/>', 'story');
        $svc->toPng('<p/>', 'square');

        $this->assertSame(['mode' => 'pdf', 'format' => 'A4'], $calls[0]);
        $this->assertSame(['mode' => 'pdf', 'format' => 'Letter'], $calls[1]);
        $this->assertSame(['mode' => 'png', 'viewport' => ['width' => 681, 'height' => 1210, 'deviceScaleFactor' => 2.0], 'select' => '.sheet'], $calls[2]);
        $this->assertSame(['width' => 794, 'height' => 794, 'deviceScaleFactor' => 2.0], $calls[3]['viewport']);
    }
}
