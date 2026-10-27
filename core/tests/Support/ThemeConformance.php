<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support;

use Illuminate\Support\Facades\View;
use Symfony\Component\Process\Process;

/**
 * P3-04: runs the kit's theme conformance gates (tools/themes/conformance/run_all.py: schema lint, grep gates, totals
 * parity, RTL static, size budgets) on kit pages that were rendered through a Blade stub, and applies the
 * report/enforce switch (tools/themes/conformance/modes.json, env THEME_CONFORMANCE_KIT / THEME_CONFORMANCE_PREMIUM).
 *
 * The renderer is the one seam to change when the real Blade themes land: render(theme, locale, page) must return the
 * page HTML a request would produce.
 */
final class ThemeConformance
{
    public const REPORT = 'report';

    public const ENFORCE = 'enforce';

    public static function repoRoot(): string
    {
        return dirname(base_path());
    }

    public static function toolsDir(): string
    {
        return self::repoRoot().'/tools/themes/conformance';
    }

    /** @return array{kit_mode: string, premium_mode: string, kit_themes: list<string>, premium_themes: list<string>} */
    public static function modes(): array
    {
        $file = json_decode((string) file_get_contents(self::toolsDir().'/modes.json'), true, 512, JSON_THROW_ON_ERROR);

        foreach (['kit_mode' => 'THEME_CONFORMANCE_KIT', 'premium_mode' => 'THEME_CONFORMANCE_PREMIUM'] as $key => $env) {
            $override = getenv($env);
            if (in_array($override, [self::REPORT, self::ENFORCE], true)) {
                $file[$key] = $override;
            }
            if (! in_array($file[$key], [self::REPORT, self::ENFORCE], true)) {
                throw new \InvalidArgumentException("modes.json {$key} must be report or enforce");
            }
        }

        return $file;
    }

    /** report|enforce for one theme slug (unknown slugs count as premium: report-only, the safe side) */
    public static function modeFor(string $theme, ?array $modes = null): string
    {
        $modes ??= self::modes();

        $kit = in_array($theme, $modes['kit_themes'], true) && ! in_array($theme, $modes['report_until_ported'] ?? [], true);

        return $kit ? $modes['kit_mode'] : $modes['premium_mode'];
    }

    /** A failing gate fails the job only for an enforced theme. */
    public static function blocks(string $theme, int $exitCode, ?array $modes = null): bool
    {
        return $exitCode !== 0 && self::modeFor($theme, $modes) === self::ENFORCE;
    }

    public static function python(): ?string
    {
        foreach (['python3', 'python'] as $bin) {
            $p = new Process([$bin, '--version']);
            $p->run();
            if ($p->isSuccessful()) {
                return $bin;
            }
        }

        return null;
    }

    /** Where rendered pages go: THEME_CONFORMANCE_OUT (kept for the Node job) or a throw-away temp dir. */
    public static function outDir(): string
    {
        $env = getenv('THEME_CONFORMANCE_OUT');

        return $env !== false && $env !== '' ? $env : sys_get_temp_dir().'/theme-conformance-'.getmypid();
    }

    /** The page HTML through the Blade stub (tests/Fixtures/views/theme-conformance/page.blade.php). */
    public static function render(string $html): string
    {
        View::addNamespace('theme-conformance', base_path('tests/Fixtures/views/theme-conformance'));

        return view('theme-conformance::page', ['html' => $html])->render();
    }

    /**
     * Copy the kit snapshot to $out and replace every prototype page with its Blade render.
     *
     * @return array{pages: int, mismatches: list<string>}
     */
    public static function renderAll(string $out): array
    {
        $src = self::toolsDir().'/kit-snapshot';
        self::rrmdir($out);
        mkdir($out, 0775, true);
        self::copyTree($src, $out);

        $pages = 0;
        $mismatches = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src.'/prototypes', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'html') {
                continue;
            }
            $rel = substr($file->getPathname(), strlen($src) + 1);
            $raw = (string) file_get_contents($file->getPathname());
            $rendered = self::render($raw);
            // Blade must not alter the markup (a `@` directive or `{{` in a prototype would): compare modulo surrounding whitespace.
            if (trim($rendered) !== trim($raw)) {
                $mismatches[] = $rel;
            }
            file_put_contents($out.'/'.$rel, $rendered);
            $pages++;
        }

        return ['pages' => $pages, 'mismatches' => $mismatches];
    }

    /**
     * run_all.py for one theme (or the self test) against the rendered tree.
     *
     * @return array{code: int, output: string}
     */
    public static function runGates(string $python, string $out, ?string $theme): array
    {
        $cmd = [$python, self::toolsDir().'/run_all.py', '--quiet'];
        $cmd = $theme === null ? [$python, self::toolsDir().'/run_all.py', '--self-test'] : [...$cmd, '--root', $out, '--theme', $theme];
        $p = new Process($cmd, null, ['PYTHONDONTWRITEBYTECODE' => '1']);
        $p->setTimeout(300);
        $p->run();

        return ['code' => (int) $p->getExitCode(), 'output' => $p->getOutput().$p->getErrorOutput()];
    }

    public static function copyTree(string $from, string $to): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $f) {
            $target = $to.'/'.substr($f->getPathname(), strlen($from) + 1);
            if ($f->isDir()) {
                @mkdir($target, 0775, true);
            } else {
                copy($f->getPathname(), $target);
            }
        }
    }

    public static function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() && ! $f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
