<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\ThemeStudio;

use Juwri\Modules\Storefront\Models\AiSectionDraft;
use Juwri\Modules\Ai\Services\AiProviderException;
use Juwri\Modules\Storefront\Services\ThemeStudio\SectionAuthor\BrowserGate;
use Juwri\Modules\Storefront\Services\ThemeStudio\SectionAuthor\Candidate;
use Juwri\Modules\Storefront\Services\ThemeStudio\SectionAuthor\PromptBuilder;
use Juwri\Modules\Storefront\Services\ThemeStudio\SectionAuthor\SectionGates;
use Juwri\Modules\Storefront\Storefront\SectionLibrary;
use Illuminate\Support\Facades\File;
use Juwri\Modules\Ai\Contracts\Ai;
use Juwri\Modules\Ai\Contracts\AiLimitExceeded;
use Juwri\Modules\Ai\Contracts\AiProviderFailure;

/**
 * Theme Studio phase E1 (spec §6): AI-written library sections, superadmin
 * only (`feature:ai_section_author`).
 *
 *  1. spec prompt   PromptBuilder — the admin's request (data sentinel) +
 *                   target verticals + few-shot library sections + the full
 *                   contract (filters, tags, drops, data-mb-* hooks, tokens,
 *                   scoping, RTL/a11y/perf rules)
 *  2. generate      AiService::json (feature `ai_section_author`) →
 *                   {type, liquid, css, schema, locales:{en,ar}, fixture_notes}
 *  3. gates         SectionGates — ALL must pass; the failures go back to the
 *                   model for up to MAX_REPAIR_ROUNDS repair rounds; every
 *                   attempt + its gate report is kept
 *  4. review queue  author() stores an AiSectionDraft (status passed|failed)
 *                   for /admin/theme-studio/sections. Nothing is written to
 *                   the library here — approval marks the row, and
 *                   `studio:section-pr` opens a git PR from a dev checkout.
 *
 * The model's answer is DATA: it only ever reaches the gates, the review
 * page (escaped) and, after human approval, a PR.
 */
class AiSectionAuthor
{
    public const FEATURE = 'ai_section_author';

    public const MAX_REPAIR_ROUNDS = 3;

    public function __construct(protected Ai $ai) {}

    /**
     * Generate + gate + persist a draft.
     *
     * @param  array<int,string>  $verticals
     * @param  array{type?:?string,library?:string,user_id?:?int,browser?:bool}  $opts
     */
    public function author(string $request, array $verticals, array $opts = []): AiSectionDraft
    {
        $library = $this->library($opts['library'] ?? '1');
        $draft = AiSectionDraft::create([
            'prompt' => mb_substr(trim($request), 0, 4000),
            'verticals' => array_values($verticals),
            'library_version' => $library->version,
            'status' => AiSectionDraft::STATUS_GENERATING,
            'created_by' => $opts['user_id'] ?? null,
        ]);

        $shots = fn (int $n): ?string => ($opts['browser'] ?? true)
            ? storage_path('app/'.$draft->storageDir().'/attempt-'.$n)
            : null;

        $result = $this->run($request, $verticals, $opts + ['shots' => $shots]);

        $final = $result['candidate'];
        $screens = [];
        foreach ($result['screenshots'] as $key => $path) {
            $screens[$key] = ltrim(str_replace(storage_path('app'), '', $path), '/');
        }

        $draft->forceFill([
            'type' => $final['type'] ?? null,
            'liquid' => $final['liquid'] ?? null,
            'css' => $final['css'] ?? null,
            'schema' => $final['schema'] ?? null,
            'locales' => $final['locales'] ?? null,
            'fixture_notes' => $final['fixture_notes'] ?? null,
            'gate_report' => $result['report'],
            'attempts' => $result['attempts'],
            'repair_rounds' => $result['repair_rounds'],
            'screenshots' => $screens,
            'error' => $result['error'] !== null ? mb_substr($result['error'], 0, 500) : null,
            'status' => $result['passed'] ? AiSectionDraft::STATUS_PASSED : AiSectionDraft::STATUS_FAILED,
        ])->save();

        return $draft;
    }

    /**
     * The pipeline without persistence (eval, tests).
     *
     * @param  list<string>  $verticals
     * @param  array{type?:?string,library?:string,user_id?:?int,shots?:(callable(int):?string)|null}  $opts
     * @return array{passed:bool,candidate:?array<string,mixed>,report:?array<string,mixed>,attempts:list<array<string,mixed>>,repair_rounds:int,screenshots:array<string,string>,error:?string}
     */
    public function run(string $request, array $verticals, array $opts = []): array
    {
        $library = $this->library($opts['library'] ?? '1');
        $prompts = new PromptBuilder($library);
        $gates = new SectionGates($library, app(BrowserGate::class));
        $hint = $opts['type'] ?? null;
        $base = $prompts->messages($request, $verticals, $hint !== null && $hint !== '' ? $hint : null);
        $shots = $opts['shots'] ?? null;
        $messages = $base;

        $attempts = [];
        $report = null;
        $candidate = null;
        $error = null;
        $passed = false;
        $screenshots = [];

        for ($round = 0; $round <= self::MAX_REPAIR_ROUNDS; $round++) {
            $started = hrtime(true);
            try {
                $answer = $this->ai->json($messages, [
                    'max_tokens' => (int) config('theme_studio.section_author.max_tokens', 8000),
                    'temperature' => 0.3,
                ], fn ($decoded) => $this->shapeOk($decoded), $opts['user_id'] ?? null, null, self::FEATURE);
            } catch (AiProviderFailure|AiLimitExceeded $e) {
                $error = $e->getMessage();
                $attempts[] = ['n' => $round + 1, 'at' => now()->toIso8601String(), 'error' => mb_substr($error, 0, 500)];
                break;
            }

            $shotsDir = $shots !== null ? $shots($round + 1) : null;
            $report = $gates->run($answer, $verticals, $shotsDir);
            [$parsed] = Candidate::fromAnswer($answer);
            $candidate = $parsed?->toArray() ?? $this->rawAnswer($answer);
            $screenshots = $report['screenshots'];
            unset($report['screenshots']);
            $attempts[] = [
                'n' => $round + 1,
                'at' => now()->toIso8601String(),
                'ms' => (int) ((hrtime(true) - $started) / 1e6),
                'answer' => $candidate,
                'report' => $report,
            ];
            if ($shotsDir !== null && $screenshots === []) {
                File::deleteDirectory($shotsDir);
            }

            if ($report['passed']) {
                $passed = true;
                break;
            }
            if ($round < self::MAX_REPAIR_ROUNDS) {
                $messages = array_merge($base, $prompts->repair($candidate, SectionGates::failures($report), $round + 1));
            }
        }

        $gateRounds = count(array_filter($attempts, fn (array $a) => isset($a['report'])));

        return [
            'passed' => $passed,
            'candidate' => $candidate,
            'report' => $report,
            'attempts' => $attempts,
            'repair_rounds' => max(0, $gateRounds - 1),
            'screenshots' => $screenshots,
            'error' => $error,
        ];
    }

    /** A cheap shape check inside AiService::json (malformed answers retry there). */
    protected function shapeOk(mixed $decoded): bool
    {
        if (! is_array($decoded) || ! is_string($decoded['liquid'] ?? null) || ! is_string($decoded['type'] ?? null)) {
            throw new AiProviderException('The reply must be one JSON object {type, liquid, css, schema, locales, fixture_notes}.', self::FEATURE, true);
        }

        return true;
    }

    /**
     * @param  array<mixed>  $answer
     * @return array<string,mixed>
     */
    protected function rawAnswer(array $answer): array
    {
        return [
            'type' => is_string($answer['type'] ?? null) ? mb_substr($answer['type'], 0, 60) : null,
            'liquid' => is_string($answer['liquid'] ?? null) ? $answer['liquid'] : null,
            'css' => is_string($answer['css'] ?? null) ? $answer['css'] : null,
            'schema' => is_array($answer['schema'] ?? null) ? $answer['schema'] : null,
            'locales' => is_array($answer['locales'] ?? null) ? $answer['locales'] : null,
            'fixture_notes' => is_string($answer['fixture_notes'] ?? null) ? $answer['fixture_notes'] : null,
        ];
    }

    protected function library(string $version): SectionLibrary
    {
        $library = SectionLibrary::forVersion($version);
        if (! $library) {
            throw new \InvalidArgumentException("Unknown section library version: {$version}");
        }

        return $library;
    }
}
