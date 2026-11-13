<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Services\Studio;

use App\Support\HostCapabilities;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * J18: headless-Chrome rendering of a standalone HTML document to PDF / PNG,
 * replacing spatie/browsershot with the same toolchain it drove: node +
 * puppeteer from core/node_modules (package.json), through headless-render.mjs
 * (next to this file). Same knobs the studio used: no-sandbox, backgrounds kept,
 * paper format with zero margins, window size + device scale, element
 * screenshot by CSS selector, 60 s timeout, optional Chrome / node paths
 * (config studio.export.chrome_path / node_path).
 *
 * Failure (no node, no puppeteer, Chrome crash, selector missing, timeout)
 * throws a RuntimeException — callers already treat a throw as "export failed".
 */
class HeadlessChrome
{
    public const TIMEOUT_SECONDS = 60;

    private ?string $chromePath;

    private ?string $nodePath;

    public function __construct(?string $chromePath = null, ?string $nodePath = null, private int $timeout = self::TIMEOUT_SECONDS)
    {
        $this->chromePath = $chromePath ?? (config('studio.export.chrome_path') ?: null);
        $this->nodePath = $nodePath ?? (config('studio.export.node_path') ?: null);
    }

    /** PDF bytes: $format is a paper name puppeteer knows (A4, Letter), zero margins, backgrounds printed. */
    public function pdf(string $html, string $format): string
    {
        return $this->run($html, ['mode' => 'pdf', 'format' => $format]);
    }

    /**
     * PNG bytes of the page at $width×$height CSS px and $scale device pixels per
     * px; with $select, only that element (it must exist).
     */
    public function screenshot(string $html, int $width, int $height, float $scale = 1.0, ?string $select = null): string
    {
        return $this->run($html, [
            'mode' => 'png',
            'viewport' => ['width' => $width, 'height' => $height, 'deviceScaleFactor' => $scale],
            'select' => $select,
        ]);
    }

    /**
     * Write the HTML + job to a private temp dir, run the script, return the output.
     *
     * @param  array<string, mixed>  $job
     */
    protected function run(string $html, array $job): string
    {
        if (! app(HostCapabilities::class)->processesAllowed()) {
            throw new RuntimeException(HostCapabilities::MESSAGE_EXPORT);
        }
        $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'juwri-chrome-'.Str::random(20);
        File::ensureDirectoryExists($dir, 0700);
        try {
            $out = $dir.'/out.'.($job['mode'] === 'pdf' ? 'pdf' : 'png');
            File::put($dir.'/page.html', $html);
            File::put($dir.'/job.json', (string) json_encode($job + [
                'html' => $dir.'/page.html',
                'out' => $out,
                'chromePath' => $this->chromePath,
                'noSandbox' => true,
                'timeoutMs' => $this->timeout * 1000,
            ]));

            $process = new Process([$this->node(), __DIR__.'/headless-render.mjs', $dir.'/job.json'], base_path(), null, null, $this->timeout + 15);
            $process->run();
            if (! $process->isSuccessful() || ! is_file($out)) {
                throw new RuntimeException('headless Chrome render failed: '.mb_substr(trim($process->getErrorOutput()) ?: trim($process->getOutput()) ?: 'no output', 0, 500));
            }

            return (string) file_get_contents($out);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    protected function node(): string
    {
        if ($this->nodePath !== null) {
            if (! @is_executable($this->nodePath)) {
                throw new RuntimeException("node binary not executable: {$this->nodePath}");
            }

            return $this->nodePath;
        }

        return (new ExecutableFinder)->find('node', null, ['/usr/local/bin', '/opt/homebrew/bin', '/usr/bin'])
            ?? throw new RuntimeException('node is not installed on this host');
    }
}
