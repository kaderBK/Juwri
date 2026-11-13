<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Console\Commands;

use App\Services\Ai\E2eStubAiService;
use Juwri\Modules\Storefront\Services\ThemeStudio\AiSectionAuthor;
use Juwri\Modules\Storefront\Services\ThemeStudio\SectionAuthor\StubSectionAuthor;
use Illuminate\Console\Command;
use Juwri\Modules\Ai\Contracts\Ai;
use Juwri\Modules\Ai\Contracts\AiProviderChain;

/**
 * Theme Studio phase E1 step 5: offline eval of the AI section author over
 * the golden prompts in tests/Fixtures/section-prompts/*.json
 * ({id, request, verticals[], type?, stub_flaw?}).
 *
 *   php artisan studio:eval-sections [--live] [--only=<id>] [--json]
 *
 * Reports, per prompt and overall: final pass, first-try pass, repair rounds
 * used, and the pass rate of every gate on the first and the final attempt.
 * Default = the deterministic E2eStubAiService (no provider call, no spend;
 * `stub_flaw: true` prompts make the stub's first draft fail so the repair
 * loop is exercised). --live uses the configured AI providers (metered).
 * Nothing is persisted and the browser gate is not run.
 */
class StudioEvalSections extends Command
{
    protected $signature = 'studio:eval-sections
        {--live : Use the configured AI providers instead of the stub}
        {--only= : Run one prompt id}
        {--json : Print the report as JSON}';

    protected $description = 'Evaluate the AI section author over the golden section prompts (gate pass rates, repair rounds)';

    public function handle(): int
    {
        $prompts = $this->prompts();
        if ($prompts === []) {
            $this->error('No prompts in tests/Fixtures/section-prompts/.');

            return self::FAILURE;
        }

        $live = (bool) $this->option('live');
        $ai = $live ? app(Ai::class) : new E2eStubAiService(app(AiProviderChain::class));
        $author = new AiSectionAuthor($ai);

        $rows = [];
        $gateFirst = [];
        $gateFinal = [];
        foreach ($prompts as $p) {
            $request = (string) $p['request'];
            if (! $live && ! empty($p['stub_flaw'])) {
                $request .= ' '.StubSectionAuthor::FLAW_MARKER;
            }
            $started = microtime(true);
            try {
                $r = $author->run($request, (array) ($p['verticals'] ?? ['all']), ['type' => $p['type'] ?? null]);
            } catch (\Throwable $e) {
                $rows[] = ['id' => $p['id'], 'passed' => false, 'first_try' => false, 'rounds' => 0, 'type' => null, 'seconds' => 0, 'error' => $e->getMessage()];

                continue;
            }

            $reports = array_values(array_filter(array_map(fn ($a) => $a['report'] ?? null, $r['attempts'])));
            $gateFirst = $this->tally($reports[0] ?? null, $gateFirst);
            $gateFinal = $this->tally(end($reports) ?: null, $gateFinal);

            $rows[] = [
                'id' => $p['id'],
                'passed' => $r['passed'],
                'first_try' => (bool) ($reports[0]['passed'] ?? false),
                'rounds' => $r['repair_rounds'],
                'type' => $r['candidate']['type'] ?? null,
                'seconds' => round(microtime(true) - $started, 1),
                'error' => $r['error'],
            ];
        }

        $n = count($rows);
        $summary = [
            'mode' => $live ? 'live' : 'stub',
            'prompts' => $n,
            'final_pass_rate' => round(100 * count(array_filter($rows, fn ($r) => $r['passed'])) / $n, 1),
            'first_try_pass_rate' => round(100 * count(array_filter($rows, fn ($r) => $r['first_try'])) / $n, 1),
            'avg_repair_rounds' => round(array_sum(array_column($rows, 'rounds')) / $n, 2),
            'max_repair_rounds' => max(array_column($rows, 'rounds')),
            'gates_first' => $this->rates($gateFirst),
            'gates_final' => $this->rates($gateFinal),
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode(['summary' => $summary, 'prompts' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(['prompt', 'type', 'passed', 'first try', 'repair rounds', 's', 'error'], array_map(fn ($r) => [
                $r['id'], $r['type'] ?? '—', $r['passed'] ? 'yes' : 'NO', $r['first_try'] ? 'yes' : 'no', $r['rounds'], $r['seconds'], mb_substr((string) $r['error'], 0, 60),
            ], $rows));
            $this->table(['gate', 'first attempt pass %', 'final attempt pass %'], array_map(fn ($id) => [
                $id, $summary['gates_first'][$id] ?? '—', $summary['gates_final'][$id] ?? '—',
            ], array_keys($summary['gates_final'] + $summary['gates_first'])));
            $this->info(sprintf(
                '%s mode: %d prompts, final pass %s%%, first-try pass %s%%, repair rounds avg %s / max %d.',
                $summary['mode'], $n, $summary['final_pass_rate'], $summary['first_try_pass_rate'], $summary['avg_repair_rounds'], $summary['max_repair_rounds']
            ));
        }

        return $summary['final_pass_rate'] >= 100.0 || $live ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string,mixed>|null  $report
     * @param  array<string,array{n:int,pass:int}>  $bucket
     * @return array<string,array{n:int,pass:int}>
     */
    protected function tally(?array $report, array $bucket): array
    {
        foreach ($report['gates'] ?? [] as $g) {
            if ($g['status'] === 'skipped') {
                continue;
            }
            $c = $bucket[(string) $g['id']] ?? ['n' => 0, 'pass' => 0];
            $bucket[(string) $g['id']] = ['n' => $c['n'] + 1, 'pass' => $c['pass'] + ($g['status'] === 'pass' ? 1 : 0)];
        }

        return $bucket;
    }

    /**
     * @param  array<string,array{n:int,pass:int}>  $bucket
     * @return array<string,float>
     */
    protected function rates(array $bucket): array
    {
        $out = [];
        foreach ($bucket as $id => $c) {
            $out[$id] = round(100 * $c['pass'] / max(1, $c['n']), 1);
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    protected function prompts(): array
    {
        $out = [];
        foreach (glob(base_path('tests/Fixtures/section-prompts/*.json')) ?: [] as $file) {
            $p = json_decode((string) file_get_contents($file), true);
            if (! is_array($p) || ! is_string($p['request'] ?? null)) {
                continue;
            }
            $p['id'] = (string) ($p['id'] ?? basename($file, '.json'));
            if ($this->option('only') && $this->option('only') !== $p['id']) {
                continue;
            }
            $out[] = $p;
        }

        return $out;
    }
}
