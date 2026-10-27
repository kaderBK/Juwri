<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Services\Docs;

use Illuminate\Support\Str;
use Juwri\Modules\Ai\Contracts\Ai;

/**
 * DOCS-PLATFORM D6-3: the docs-grounded help assistant. Answers a user's question
 * ONLY from the shipped Help Center docs (RAG), citing the pages it used, and
 * refers the user to support when the docs don't cover it. Ships DARK — the
 * super-admin flag `docs_assistant_enabled` defaults off.
 *
 * Trust model: the doc corpus is repo-authored (trusted); the USER QUESTION is
 * untrusted and is (a) length-capped, (b) newline/role-marker stripped so it
 * cannot forge a chat turn, and (c) placed only in the user turn — never the
 * system prompt. The model is told to answer solely from CONTEXT and to never
 * recommend a feature that is not described there, so it cannot invent
 * capabilities the tenant has not bought.
 */
class DocsAssistantService
{
    private const MAX_Q = 500;

    private const TOP_K = 6;

    public static function enabled(): bool
    {
        return filter_var(config('settings.docs_assistant_enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return array{answer:string, sources:list<array{slug:string,title:string,url:string}>}
     */
    public function ask(string $question, string $pillar = 'users'): array
    {
        // Untrusted input: cap length, collapse newlines + role markers.
        $q = Str::limit(trim(preg_replace('/[\r\n]+/', ' ', $question)), self::MAX_Q, '');
        if ($q === '') {
            return ['answer' => 'Please type a question.', 'sources' => []];
        }

        [$context, $allow] = $this->context($pillar, $q);
        if ($allow === []) {
            return [
                'answer' => "I couldn't find anything about that in the guides. Try rephrasing, or contact support.",
                'sources' => [],
            ];
        }

        $slugList = implode(', ', array_keys($allow));
        $system = <<<SYS
You are a help assistant for a restaurant platform. Answer the user's question USING ONLY the CONTEXT documents below.
Rules:
- Use only facts stated in CONTEXT. If CONTEXT does not answer the question, say you don't have that in the guides and suggest contacting support. Do not guess.
- Never describe or recommend a feature that is not present in CONTEXT.
- Be concise and practical. Refer to dashboard labels exactly as CONTEXT writes them.
- Cite the pages you used by their slug.
Reply as JSON: {"answer": "<plain text answer>", "sources": ["<slug>", ...]}. Sources must be a subset of: {$slugList}.

CONTEXT:
{$context}
SYS;

        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $q],
        ];

        /** @var Ai $ai */
        $ai = app(Ai::class);
        $result = $ai->json(
            $messages,
            [],
            fn ($d) => is_array($d) && isset($d['answer']) && is_string($d['answer']),
            null,
            null,
            'chatbot'
        );

        $answer = is_string($result['answer'] ?? null) ? trim($result['answer']) : '';
        if ($answer === '') {
            $answer = "I couldn't answer that from the guides. Please contact support.";
        }

        // Resolve cited slugs against the allowlist only — the model cannot cite a
        // page that was not in CONTEXT.
        $sources = [];
        foreach ((array) ($result['sources'] ?? []) as $slug) {
            if (is_string($slug) && isset($allow[$slug])) {
                $sources[$slug] = $allow[$slug];
            }
        }

        return ['answer' => $answer, 'sources' => array_values($sources)];
    }

    /**
     * Build the CONTEXT block + the slug→meta allowlist from the top-scoring docs.
     *
     * @return array{0:string, 1:array<string,array{slug:string,title:string,url:string}>}
     */
    private function context(string $pillar, string $q): array
    {
        $map = [
            'users' => ['users', '/help-center'],
            'shoppers' => ['shoppers', '/fusion/help'],
            'resellers' => ['resellers', '/reseller/help'],
        ];
        [$dir, $base] = $map[$pillar] ?? $map['users'];

        $terms = array_values(array_unique(array_filter(
            preg_split('/[^a-z0-9]+/i', mb_strtolower($q)) ?: [],
            fn ($t) => mb_strlen($t) >= 3
        )));
        if ($terms === []) {
            return ['', []];
        }

        $scored = [];
        foreach (glob(resource_path('docs/'.$dir.'/*.md')) as $file) {
            $slug = basename($file, '.md');
            if ($slug === 'README' || ! preg_match('/^[a-z0-9-]+$/', $slug)) {
                continue;
            }
            $md = (string) file_get_contents($file);
            if (str_starts_with($md, "---\n") && ($end = strpos($md, "\n---\n", 4)) !== false) {
                $md = substr($md, $end + 5);
            }
            $title = preg_match('/^#\s+(.+)$/m', $md, $m) ? trim($m[1]) : Str::headline($slug);
            $body = mb_strtolower(strip_tags($md));
            $score = 0;
            foreach ($terms as $t) {
                $score += (str_contains(mb_strtolower($title), $t) ? 5 : 0) + min(substr_count($body, $t), 8);
            }
            if ($score > 0) {
                $scored[] = ['slug' => $slug, 'title' => $title, 'md' => $md, 'score' => $score];
            }
        }
        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
        $scored = array_slice($scored, 0, self::TOP_K);

        $context = '';
        $allow = [];
        foreach ($scored as $d) {
            // Cap each doc so a long page can't blow the context window.
            $context .= '### '.$d['slug'].' — '.$d['title']."\n".Str::limit($d['md'], 2500, '')."\n\n";
            $allow[$d['slug']] = ['slug' => $d['slug'], 'title' => $d['title'], 'url' => $base.'/'.$d['slug']];
        }

        return [$context, $allow];
    }
}
