<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Console\Commands;

use App\Services\Ai\E2eStubAiService;
use Juwri\Modules\Storefront\Services\ThemeStudio\Ai\Palette;
use Juwri\Modules\Storefront\Services\ThemeStudio\Ai\ProposalRules;
use Juwri\Modules\Storefront\Services\ThemeStudio\Ai\StoreVars;
use Juwri\Modules\Storefront\Services\ThemeStudio\Ai\StudioThemes;
use App\Services\ThemeStudio\AiThemeBuilder;
use Juwri\Modules\Storefront\Services\ThemeStudio\Recipes;
use Juwri\Modules\Storefront\Storefront\MarketplaceThemeResolver;
use Illuminate\Console\Command;
use Juwri\Modules\Ai\Contracts\Ai;
use Juwri\Modules\Ai\Contracts\AiProviderChain;

/**
 * Theme Studio phase D5: offline evaluation of the AI theme builder over the
 * golden briefs in tests/Fixtures/theme-briefs/*.json (8 verticals × 5, half
 * Arabic-first), each run against its vertical's demo store.
 *
 * Per brief / proposal it measures: validator pass (first try, and after the
 * retry — i.e. no fallback), WCAG AA palette, render errors (sections dropped
 * by the strict render check), required catalogue section, ProposalRules
 * clean, copy length compliance, and language correctness (default-language
 * copy is Arabic for Arabic-first briefs and not Arabic otherwise; the Arabic
 * i18n copy is Arabic).
 *
 * By default the deterministic stub answers (no provider call, no cost);
 * `--live` uses the configured AI provider chain (costs real calls).
 * Exits non-zero when a hard target fails: AA 100%, render errors 0,
 * catalogue coverage 100%, rules clean 100%.
 */
class StudioEvalAi extends Command
{
    protected $signature = 'studio:eval-ai
        {--live : Use the configured AI provider instead of the deterministic stub}
        {--limit=0 : Only the first N briefs}
        {--only= : Only briefs of this vertical (store type)}
        {--json= : Also write the full report as JSON to this path}';

    protected $description = 'Evaluate the Theme Studio AI theme builder over the golden briefs';

    public function handle(): int
    {
        $files = glob(base_path('tests/Fixtures/theme-briefs/*.json')) ?: [];
        sort($files);
        $briefs = [];
        foreach ($files as $file) {
            $brief = json_decode((string) file_get_contents($file), true);
            if (is_array($brief) && isset($brief['vertical'])) {
                $brief['id'] ??= basename($file, '.json');
                $briefs[] = $brief;
            }
        }
        if (is_string($this->option('only')) && $this->option('only') !== '') {
            $briefs = array_values(array_filter($briefs, fn ($b) => $b['vertical'] === $this->option('only')));
        }
        if ((int) $this->option('limit') > 0) {
            $briefs = array_slice($briefs, 0, (int) $this->option('limit'));
        }
        if ($briefs === []) {
            $this->error('No golden briefs found in tests/Fixtures/theme-briefs/.');

            return self::FAILURE;
        }

        $live = (bool) $this->option('live');
        if (! $live) {
            app()->instance(Ai::class, new E2eStubAiService(app(AiProviderChain::class)));
            app()->forgetInstance(AiThemeBuilder::class);
        } else {
            $this->warn('Live mode: every brief makes real AI provider calls (1 compose + 1 translate per extra language).');
        }
        $builder = app(AiThemeBuilder::class);
        $limits = (array) config('theme_studio.ai.copy_limits', []);

        $rows = [];
        $totals = ['briefs' => 0, 'first_try' => 0, 'no_fallback' => 0, 'proposals' => 0, 'aa' => 0, 'catalogue' => 0,
            'rules' => 0, 'copy_ok' => 0, 'lang_ok' => 0, 'render_errors' => 0, 'errors' => 0, 'ms' => 0];

        foreach ($briefs as $brief) {
            $totals['briefs']++;
            $lang = (string) ($brief['default_language'] ?? 'en');
            try {
                $result = $builder->generate(null, (array) ($brief['brief'] ?? []), [
                    'vertical' => (string) $brief['vertical'],
                    'default_language' => $lang,
                    'vars' => StoreVars::forDemo((string) $brief['vertical'], $lang),
                ]);
            } catch (\Throwable $e) {
                $totals['errors']++;
                $rows[] = ['id' => $brief['id'], 'error' => $e->getMessage()];
                $this->line("  <error>{$brief['id']}</error> {$e->getMessage()}");

                continue;
            }

            $firstTry = ! $result['fallback'] && ($result['meta']['attempts'] ?? 0) === 1;
            $totals['first_try'] += $firstTry ? 1 : 0;
            $totals['no_fallback'] += $result['fallback'] ? 0 : 1;
            $totals['render_errors'] += count($result['meta']['dropped'] ?? []);
            $totals['ms'] += (int) ($result['meta']['ms'] ?? 0);

            $group = $result['brief']['store']['vertical_group'];
            $ctxBase = ['group' => $group, 'store_type' => $result['brief']['store']['store_type'],
                'ordering' => (bool) $result['brief']['ordering'], 'max_sections' => (int) config('theme_studio.ai.max_sections', 14)];

            $proposals = [];
            foreach ($result['proposals'] as $p) {
                $totals['proposals']++;
                $theme = MarketplaceThemeResolver::resolve('mp:'.$p['theme_slug'].'@'.$p['theme_version'])
                    ?? StudioThemes::all()[0];
                $rules = new ProposalRules($theme);
                $template = StudioThemes::template($theme);

                $aa = Palette::passes($p['tokens'], $template);
                $types = array_column($p['layout']['sections'], 'type');
                $catalogue = in_array(ProposalRules::CATALOGUE[$group] ?? '', $types, true);
                $recipe = Recipes::find($p['recipe_id']);
                $ruleErrors = $rules->check($p['layout'], $ctxBase + ['priority' => $brief['brief']['priority'] ?? ($recipe['priority'] ?? null)]);

                $copy = $rules->copy($p['layout']);
                $copyOk = true;
                $langOk = true;
                foreach ($copy as $entry) {
                    $len = mb_strlen(strip_tags($entry['value']));
                    if ($len > (int) ($limits[$entry['type']] ?? 600)) {
                        $copyOk = false;
                    }
                    $arabic = (bool) preg_match('/\p{Arabic}/u', $entry['value']);
                    if (str_starts_with($lang, 'ar') !== $arabic) {
                        $langOk = false;
                    }
                }
                foreach ((array) ($p['layout']['i18n']['ar'] ?? []) as $value) {
                    if (! preg_match('/\p{Arabic}/u', (string) $value)) {
                        $langOk = false;
                    }
                }

                $totals['aa'] += $aa ? 1 : 0;
                $totals['catalogue'] += $catalogue ? 1 : 0;
                $totals['rules'] += $ruleErrors === [] ? 1 : 0;
                $totals['copy_ok'] += $copyOk ? 1 : 0;
                $totals['lang_ok'] += $langOk ? 1 : 0;

                $proposals[] = [
                    'id' => $p['id'], 'recipe' => $p['recipe_id'], 'theme' => $p['theme_slug'], 'sections' => count($types),
                    'aa' => $aa, 'catalogue' => $catalogue, 'rules' => $ruleErrors, 'copy_ok' => $copyOk, 'lang_ok' => $langOk,
                    'copy' => count($copy), 'i18n' => array_map('count', (array) ($p['layout']['i18n'] ?? [])),
                ];
            }

            $rows[] = [
                'id' => $brief['id'], 'vertical' => $brief['vertical'], 'lang' => $lang,
                'fallback' => $result['fallback'], 'fallback_reason' => $result['fallback_reason'],
                'attempts' => $result['meta']['attempts'] ?? 0, 'dropped' => $result['meta']['dropped'] ?? [],
                'first_try_errors' => $result['meta']['first_try_errors'] ?? [],
                'ms' => $result['meta']['ms'] ?? 0, 'proposals' => $proposals,
            ];
        }

        $this->table(
            ['brief', 'lang', 'fallback', 'try', 'props', 'AA', 'catalogue', 'rules', 'copy', 'lang ok', 'dropped', 'ms'],
            array_map(fn ($r) => isset($r['error']) ? [$r['id'], '', 'ERROR', '', '', '', '', '', '', '', '', ''] : [
                $r['id'], $r['lang'], $r['fallback'] ? ($r['fallback_reason'] ?? 'yes') : 'no', $r['attempts'], count($r['proposals']),
                count(array_filter($r['proposals'], fn ($p) => $p['aa'])),
                count(array_filter($r['proposals'], fn ($p) => $p['catalogue'])),
                count(array_filter($r['proposals'], fn ($p) => $p['rules'] === [])),
                count(array_filter($r['proposals'], fn ($p) => $p['copy_ok'])),
                count(array_filter($r['proposals'], fn ($p) => $p['lang_ok'])),
                count($r['dropped']), $r['ms'],
            ], $rows)
        );

        $pct = fn (int $n, int $of) => $of > 0 ? round(100 * $n / $of, 1).'%' : 'n/a';
        $n = max(1, $totals['proposals']);
        $summary = [
            'mode' => $live ? 'live' : 'stub',
            'briefs' => $totals['briefs'],
            'errors' => $totals['errors'],
            'validator_first_try' => $pct($totals['first_try'], $totals['briefs']),
            'validator_after_retry' => $pct($totals['no_fallback'], $totals['briefs']),
            'proposals' => $totals['proposals'],
            'aa' => $pct($totals['aa'], $n),
            'catalogue_coverage' => $pct($totals['catalogue'], $n),
            'rules_clean' => $pct($totals['rules'], $n),
            'copy_length_ok' => $pct($totals['copy_ok'], $n),
            'language_ok' => $pct($totals['lang_ok'], $n),
            'render_errors' => $totals['render_errors'],
            'avg_ms' => (int) round($totals['ms'] / max(1, $totals['briefs'] - $totals['errors'])),
        ];
        foreach ($summary as $key => $value) {
            $this->line(sprintf('  %-24s %s', $key, (string) $value));
        }

        if (is_string($this->option('json')) && $this->option('json') !== '') {
            file_put_contents((string) $this->option('json'), (string) json_encode(['summary' => $summary, 'briefs' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        $hardFail = $totals['errors'] > 0
            || $totals['aa'] < $totals['proposals']
            || $totals['catalogue'] < $totals['proposals']
            || $totals['rules'] < $totals['proposals']
            || $totals['render_errors'] > 0;
        if ($hardFail) {
            $this->error('Hard targets missed (AA 100%, catalogue 100%, rules 100%, 0 render errors, 0 errors).');

            return self::FAILURE;
        }
        $this->info('All hard targets met.');

        return self::SUCCESS;
    }
}
