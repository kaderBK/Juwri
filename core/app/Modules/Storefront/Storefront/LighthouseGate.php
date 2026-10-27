<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Storefront;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * P4-3: the performance + accessibility gate (Shopify-grade bars). Runs
 * Lighthouse headless on the candidate URLs (the P4-4 pipeline supplies the
 * seed-store preview URLs), on BOTH desktop and mobile:
 *
 *   - average performance  >= 60  (config platform.lighthouse.min_performance)
 *   - minimum accessibility >= 90 (config platform.lighthouse.min_accessibility)
 *
 * Below bar = auto-reject, with the per-run scores in the report the author
 * sees. The runner shells out to `npx lighthouse`; verdict logic is pure and
 * fixture-testable.
 */
class LighthouseGate
{
    /**
     * @param  list<string>  $urls  pages to audit (home + menu of the seed store)
     * @return array{passed:bool, performance:float, accessibility:float, runs:list<array{url:string,form_factor:string,performance:float,accessibility:float}>, reasons:list<string>}
     */
    public function evaluate(array $urls): array
    {
        $runs = [];
        foreach ($urls as $url) {
            foreach (['mobile', 'desktop'] as $formFactor) {
                $scores = $this->run($url, $formFactor);
                $runs[] = [
                    'url' => $url,
                    'form_factor' => $formFactor,
                    'performance' => $scores['performance'],
                    'accessibility' => $scores['accessibility'],
                ];
            }
        }

        return $this->verdict($runs);
    }

    /**
     * Pure verdict from a set of runs — fixture-testable without Chrome.
     *
     * @param  list<array{url:string,form_factor:string,performance:float,accessibility:float}>  $runs
     */
    public function verdict(array $runs): array
    {
        if ($runs === []) {
            return ['passed' => false, 'performance' => 0.0, 'accessibility' => 0.0, 'runs' => [], 'reasons' => ['no audit runs completed']];
        }

        $minPerf = (float) config('platform.lighthouse.min_performance', 60);
        $minA11y = (float) config('platform.lighthouse.min_accessibility', 90);

        $avgPerf = round(array_sum(array_column($runs, 'performance')) / count($runs), 1);
        $minA11yScore = (float) min(array_column($runs, 'accessibility'));

        $reasons = [];
        if ($avgPerf < $minPerf) {
            $reasons[] = "average performance {$avgPerf} is below the required {$minPerf}";
        }
        if ($minA11yScore < $minA11y) {
            $reasons[] = "accessibility {$minA11yScore} is below the required {$minA11y}";
        }

        return [
            'passed' => $reasons === [],
            'performance' => $avgPerf,
            'accessibility' => $minA11yScore,
            'runs' => $runs,
            'reasons' => $reasons,
        ];
    }

    /** Is the lighthouse tooling available on this host? */
    public static function available(): bool
    {
        try {
            $p = Process::fromShellCommandline('npx --no-install lighthouse --version');
            $p->setTimeout(30)->run();

            return $p->isSuccessful();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * One headless Lighthouse run. Overridable so tests inject fixtures.
     *
     * @return array{performance: float, accessibility: float}
     */
    protected function run(string $url, string $formFactor): array
    {
        $preset = $formFactor === 'desktop' ? '--preset=desktop' : '';
        $cmd = trim(sprintf(
            'npx --yes lighthouse %s %s --only-categories=performance,accessibility'
            .' --output=json --output-path=stdout --quiet'
            .' --chrome-flags="--headless=new --no-sandbox --ignore-certificate-errors"',
            escapeshellarg($url),
            $preset,
        ));

        $process = Process::fromShellCommandline($cmd);
        $process->setTimeout((int) config('platform.lighthouse.timeout', 240));
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('lighthouse failed for '.$url.' ('.$formFactor.'): '.substr($process->getErrorOutput(), 0, 400));
        }

        $json = json_decode($process->getOutput(), true);
        $perf = $json['categories']['performance']['score'] ?? null;
        $a11y = $json['categories']['accessibility']['score'] ?? null;
        if ($perf === null || $a11y === null) {
            throw new RuntimeException('lighthouse returned no scores for '.$url);
        }

        // Lighthouse scores are 0–1; the bars speak 0–100.
        return ['performance' => round($perf * 100, 1), 'accessibility' => round($a11y * 100, 1)];
    }
}
