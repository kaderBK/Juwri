<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services\Ai;

use App\Services\ThemeStudio\AiThemeBuilder;
use Juwri\Modules\Ai\Services\AiService;
use Juwri\Modules\Storefront\Services\ThemeStudio\Ai\StubComposer;
use Juwri\Modules\Storefront\Services\ThemeStudio\AiSectionAuthor;
use Juwri\Modules\Storefront\Services\ThemeStudio\SectionAuthor\StubSectionAuthor;
use Juwri\Modules\Storefront\Services\ThemeStudio\SectionRestyler;

/**
 * E2E-only deterministic AI stub (rev/platform-e2e-v2 V0.2).
 *
 * Bound over {@see AiService} by {@see \App\Providers\E2eStubServiceProvider}
 * ONLY when the app is local/testing AND env('E2E_STUBS') is truthy — so it can
 * NEVER run in production and NEVER calls a real provider or spends a real key.
 *
 * It returns stable, self-describing output so a journey can assert an AI write
 * path (biolink/blog/translate) end to end without flake:
 *   - complete(): a deterministic sentence echoing the last user message.
 *   - json(): a deterministic object; if a validator is supplied it is honoured
 *     (the stub tries a couple of common shapes so validated call-sites pass).
 *
 *   - json() with feature `theme_generate` (Theme Studio AI builder): three
 *     valid proposals / marked translations (ThemeStudio\Ai\StubComposer).
 *   - json() with feature `ai_section_author` (Theme Studio E1): one valid
 *     library section (ThemeStudio\SectionAuthor\StubSectionAuthor).
 *   - json() with feature `section_restyle` (Theme Studio E2): a token-based
 *     CSS variant rooted on the requested section wrapper.
 *
 * Image generation and transcription are intentionally NOT stubbed here (no v2
 * journey exercises them); if a future journey needs them, extend this class.
 */
class E2eStubAiService extends AiService
{
    /**
     * Deterministic free-text completion — no provider call, no metering.
     *
     * @param  array<mixed, mixed>  $messages
     * @param  array<mixed, mixed>  $options
     */
    public function complete(array $messages, array $options = [], ?int $userId = null, ?int $storeId = null, string $feature = 'general'): string
    {
        $last = '';
        foreach (array_reverse($messages) as $m) {
            if (($m['role'] ?? '') === 'user' && ! empty($m['content'])) {
                $last = is_string($m['content']) ? $m['content'] : json_encode($m['content']);
                break;
            }
        }
        $last = trim(mb_substr($last, 0, 120));

        return '[E2E-AI-STUB] '.($last !== '' ? $last : 'ok').' — deterministic response for feature "'.$feature.'".';
    }

    /**
     * Deterministic JSON completion — honours a supplied validator when possible.
     *
     * @param  array<mixed, mixed>  $messages
     * @param  array<mixed, mixed>  $options
     * @return array<mixed, mixed>
     */
    public function json(array $messages, array $options = [], ?callable $validator = null, ?int $userId = null, ?int $storeId = null, string $feature = 'general'): array
    {
        // Theme Studio phase D: the AI theme builder's compose + translate calls
        // get a valid, deterministic answer (3 proposals / marked translations).
        if ($feature === AiThemeBuilder::FEATURE
            && ($answer = StubComposer::respond($messages)) !== null) {
            if ($validator) {
                try {
                    $validator($answer);
                } catch (\Throwable $e) {
                    // the builder re-checks the answer itself
                }
            }

            return $answer;
        }

        // Theme Studio phase E1: the AI section author gets a deterministic,
        // gate-passing section (a flawed first draft when the request carries
        // StubSectionAuthor::FLAW_MARKER, so the repair loop runs).
        if ($feature === AiSectionAuthor::FEATURE) {
            $answer = StubSectionAuthor::respond($messages);
            if ($validator) {
                $validator($answer);
            }

            return $answer;
        }

        // Theme Studio E2: "Restyle this section" — a deterministic, scoped CSS
        // variant for the section id named in the request (SectionRestyler).
        if ($feature === SectionRestyler::FEATURE) {
            $data = json_decode((string) ($messages[count($messages) - 1]['content'] ?? ''), true);
            $scope = is_array($data) && is_string($data['wrapper'] ?? null) ? $data['wrapper'] : '#mb-section-x';
            $answer = ['css' => $scope.'{background:var(--tc-surface);border-radius:var(--tr-card);padding-inline:var(--ts-gap-3)}'
                .$scope.' h2{color:var(--tc-primary);margin-left:8px}'];
            if ($validator) {
                try {
                    $validator($answer);
                } catch (\Throwable $e) {
                    // the restyler re-cleans the answer itself
                }
            }

            return $answer;
        }

        $candidates = [
            ['text' => '[E2E-AI-STUB] deterministic', 'title' => 'E2E Stub', 'items' => ['one', 'two', 'three'], 'feature' => $feature],
            ['result' => '[E2E-AI-STUB] deterministic', 'feature' => $feature],
            ['content' => '[E2E-AI-STUB] deterministic', 'feature' => $feature],
        ];

        if ($validator) {
            foreach ($candidates as $c) {
                try {
                    if ($validator($c)) {
                        return $c;
                    }
                } catch (\Throwable $e) {
                    // a strict validator may throw on a shape it dislikes; try next
                }
            }
        }

        return $candidates[0];
    }
}
