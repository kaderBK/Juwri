<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\ThemeStudio\SectionAuthor;

use App\Support\HostCapabilities;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Theme Studio phase E1: the optional browser gate (pattern of
 * RunThemeLighthouseGate — an external tool is an ADDITIONAL signal; when it
 * is missing the gate is recorded as `skipped` with the reason and never
 * blocks). Runs browser-check.mjs with puppeteer from core/node_modules:
 * en/ar × 360px/1280px screenshots, horizontal-overflow at 360px, empty
 * box, CLS, and axe-core a11y (basic checks when axe-core is absent).
 *
 * Disable with config `theme_studio.section_author.browser` = false (the
 * test suite does, so it never launches Chrome).
 */
class BrowserGate
{
    public const TIMEOUT_SECONDS = 120;

    /** Why the gate cannot run on this host, or null when it can. */
    public function unavailableReason(): ?string
    {
        if (! (bool) config('theme_studio.section_author.browser', true)) {
            return 'disabled by config (theme_studio.section_author.browser = false)';
        }
        if (! app(HostCapabilities::class)->processesAllowed()) {
            return 'PHP process functions (proc_open) are disabled on this host';
        }
        if ($this->node() === null) {
            return 'node is not installed on this host';
        }
        if (! is_dir(base_path('node_modules/puppeteer'))) {
            return 'puppeteer is not installed (run npm install in core/)';
        }

        return null;
    }

    /**
     * @param  list<array{name:string,lang:string,html:string}>  $pages
     * @return array{status:string,errors:list<string>,warnings:list<string>,skipped:?string,axe:?string,pages:list<array<string,mixed>>,screenshots:array<string,string>}
     */
    public function run(array $pages, string $outDir): array
    {
        $result = ['status' => 'skipped', 'errors' => [], 'warnings' => [], 'skipped' => null, 'axe' => null, 'pages' => [], 'screenshots' => []];
        if (($reason = $this->unavailableReason()) !== null) {
            $result['skipped'] = $reason;

            return $result;
        }

        File::ensureDirectoryExists($outDir);
        $jobPages = [];
        foreach ($pages as $p) {
            $file = $outDir.'/'.$p['name'].'.html';
            File::put($file, $p['html']);
            $jobPages[] = ['name' => $p['name'], 'file' => $file, 'lang' => $p['lang']];
        }
        $jobFile = $outDir.'/job.json';
        File::put($jobFile, (string) json_encode(['pages' => $jobPages, 'outDir' => $outDir]));

        $process = new Process([(string) $this->node(), __DIR__.'/browser-check.mjs', $jobFile], base_path(), null, null, self::TIMEOUT_SECONDS);
        try {
            $process->run();
        } catch (\Throwable $e) {
            $result['skipped'] = 'browser run failed: '.mb_substr($e->getMessage(), 0, 200);

            return $result;
        } finally {
            foreach ($jobPages as $p) {
                @unlink($p['file']);
            }
            @unlink($jobFile);
        }

        $lines = array_values(array_filter(explode("\n", trim($process->getOutput()))));
        $decoded = json_decode((string) end($lines), true);
        if (! $process->isSuccessful() || ! is_array($decoded)) {
            $result['skipped'] = 'browser run failed: '.mb_substr(trim($process->getErrorOutput()) ?: 'no report', 0, 300);

            return $result;
        }
        if (isset($decoded['skipped'])) {
            $result['skipped'] = (string) $decoded['skipped'];

            return $result;
        }

        $result['axe'] = (string) ($decoded['axe'] ?? '');
        foreach ((array) ($decoded['pages'] ?? []) as $page) {
            $label = ($page['lang'] ?? '?').' '.($page['viewport'] ?? '?');
            $result['pages'][] = array_diff_key($page, ['screenshot' => true]);
            if (is_string($page['screenshot'] ?? null) && is_file($page['screenshot'])) {
                $result['screenshots'][($page['lang'] ?? 'x').'-'.($page['viewport'] ?? 'x')] = $page['screenshot'];
            }
            if (($page['viewport'] ?? '') === 'mobile' && ! empty($page['overflow'])) {
                $result['errors'][] = "{$label}: horizontal overflow at 360px (document is {$page['scrollWidth']}px wide).";
            }
            if ((float) ($page['cls'] ?? 0) > 0.1) {
                $result['errors'][] = "{$label}: layout shift {$page['cls']} (budget 0.1) — size images and reserve space.";
            }
            foreach ((array) ($page['basic'] ?? []) as $problem) {
                $result['errors'][] = "{$label}: a11y — {$problem}.";
            }
            foreach ((array) ($page['axe'] ?? []) as $v) {
                $line = "{$label}: axe {$v['id']} ({$v['impact']}) — {$v['help']}";
                if (in_array($v['impact'] ?? '', ['serious', 'critical'], true)) {
                    $result['errors'][] = $line;
                } else {
                    $result['warnings'][] = $line;
                }
            }
        }
        $result['errors'] = array_values(array_unique($result['errors']));
        $result['status'] = $result['errors'] === [] ? 'pass' : 'fail';

        return $result;
    }

    protected function node(): ?string
    {
        $configured = config('theme_studio.section_author.node');
        if (is_string($configured) && $configured !== '' && is_executable($configured)) {
            return $configured;
        }

        return (new ExecutableFinder)->find('node', null, ['/usr/local/bin', '/opt/homebrew/bin', '/usr/bin']);
    }
}
