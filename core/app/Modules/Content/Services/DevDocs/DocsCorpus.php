<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Services\DevDocs;

/**
 * Dev MCP (rev/dev-mcp §3): loader for the developer docs corpus at
 * resources/docs/dev, plus (D2-9 M1-2) the platform's PUBLIC help pillars.
 * Markdown files, one topic each; chunks split on `##` headings for search.
 * Cached per-file by filemtime so edits show up without a deploy step but
 * repeat requests don't re-read the tree.
 *
 * Paths are corpus-relative without extension. The dev pillar keeps its
 * historical UNPREFIXED paths ("themes/filters", "learn-overview") — the shape
 * MCP clients and the pinned DevMcp*Test contracts already depend on. Every
 * OTHER public pillar is exposed pillar-PREFIXED ("users/getting-started",
 * "resellers/onboarding"). The `internal/` pillar is NEVER enumerated or
 * resolvable — it holds operator runbooks, not public docs.
 */
class DocsCorpus
{
    /** @var array<string, array{title: string, body: string, mtime: int}> */
    private static array $cache = [];

    /** Pillars that are never searchable or fetchable through the public Dev MCP. */
    private const PRIVATE_PILLARS = ['internal'];

    /** The dev pillar's directory. Kept for backward-compat (unprefixed root). */
    public static function baseDir(): string
    {
        return resource_path('docs/dev');
    }

    /**
     * Public doc roots as pillar-name => [absolute dir, path prefix]. `dev` is
     * the unprefixed root (prefix ''); every other public pillar under
     * resources/docs is prefixed with its name. `internal/` is excluded here so
     * it can never be enumerated. Optionally filter to a subset of pillar names.
     *
     * @param  list<string>|null  $only  Pillar names to keep (null = all public).
     * @return array<string, array{0: string, 1: string}>
     */
    public static function roots(?array $only = null): array
    {
        $docsDir = resource_path('docs');
        $roots = ['dev' => [$docsDir.'/dev', '']];

        if (is_dir($docsDir)) {
            foreach (scandir($docsDir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..' || $entry === 'dev') {
                    continue;
                }
                if (in_array($entry, self::PRIVATE_PILLARS, true)) {
                    continue;
                }
                if (is_dir($docsDir.'/'.$entry)) {
                    $roots[$entry] = [$docsDir.'/'.$entry, $entry.'/'];
                }
            }
        }

        if ($only !== null) {
            $roots = array_intersect_key($roots, array_flip($only));
        }

        return $roots;
    }

    /**
     * Public pillar names (dev + the prefixed help pillars), sorted.
     *
     * @return list<string>
     */
    public static function pillars(): array
    {
        $names = array_keys(static::roots());
        sort($names);

        return $names;
    }

    /**
     * All corpus paths across the requested pillars, sorted. dev paths are
     * unprefixed; other pillars carry their "<pillar>/" prefix.
     *
     * @param  list<string>|null  $pillars  null = every public pillar.
     * @return list<string>
     */
    public static function paths(?array $pillars = null): array
    {
        $paths = [];
        foreach (static::roots($pillars) as [$dir, $prefix]) {
            if (! is_dir($dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if ($file->isFile() && $file->getExtension() === 'md') {
                    $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($dir))), '/');
                    $paths[] = $prefix.substr($rel, 0, -3);
                }
            }
        }
        sort($paths);

        return $paths;
    }

    /**
     * Map a corpus path to its candidate absolute .md file, honouring pillar
     * prefixes. Always returns a path; whether it EXISTS is the caller's
     * is_file() check. Anything under a private pillar (e.g. "internal/…") maps
     * to a dev-root path that does not exist → get() returns null → never leaks.
     */
    private static function resolveFile(string $path): string
    {
        foreach (static::roots() as [$dir, $prefix]) {
            if ($prefix !== '' && str_starts_with($path, $prefix)) {
                return $dir.'/'.substr($path, strlen($prefix)).'.md';
            }
        }

        // No pillar prefix matched → the unprefixed dev root.
        return static::baseDir().'/'.$path.'.md';
    }

    /** Full doc: ['title' => ..., 'body' => ...]. Null if unknown path. */
    public static function get(string $path): ?array
    {
        // Corpus paths are slugs — refuse anything that could escape the dir.
        if (! preg_match('/^[a-z0-9][a-z0-9\/_-]*$/i', $path) || str_contains($path, '..')) {
            return null;
        }

        $file = self::resolveFile($path);
        if (! is_file($file)) {
            return null;
        }

        $mtime = (int) filemtime($file);
        if (! isset(static::$cache[$path]) || static::$cache[$path]['mtime'] !== $mtime) {
            $body = (string) file_get_contents($file);
            // D4-4: drop the YAML front-matter block so it neither pollutes search
            // nor is returned verbatim to an agent by fetch_full_doc.
            if (str_starts_with($body, "---\n") && ($end = strpos($body, "\n---\n", 4)) !== false) {
                $body = substr($body, $end + 5);
            }
            static::$cache[$path] = ['title' => static::title($body, $path), 'body' => $body, 'mtime' => $mtime];
        }

        return ['title' => static::$cache[$path]['title'], 'body' => static::$cache[$path]['body']];
    }

    /**
     * Every doc split into search chunks on `##` headings.
     *
     * @param  list<string>|null  $pillars  null = every public pillar.
     * @return list<array{doc: string, title: string, section: string, text: string}>
     */
    public static function chunks(?array $pillars = null): array
    {
        $chunks = [];
        foreach (static::paths($pillars) as $path) {
            $doc = static::get($path);
            if (! $doc) {
                continue;
            }
            foreach (preg_split('/^(?=## )/m', $doc['body']) as $piece) {
                $piece = trim($piece);
                if ($piece === '') {
                    continue;
                }
                $section = str_starts_with($piece, '## ')
                    ? trim(strtok(substr($piece, 3), "\n"))
                    : '(intro)';
                $chunks[] = ['doc' => $path, 'title' => $doc['title'], 'section' => $section, 'text' => $piece];
            }
        }

        return $chunks;
    }

    private static function title(string $body, string $fallback): string
    {
        return preg_match('/^# (.+)$/m', $body, $m) ? trim($m[1]) : $fallback;
    }

    /**
     * Keyword search over chunks. Scoring per term (spec §2.2): doc-title hit
     * ×3, chunk heading ×2, body ×1 per occurrence (capped so one spammy chunk
     * can't drown the rest). Multi-term queries sum; chunks matching more
     * DISTINCT terms rank above one-term-many-times chunks.
     *
     * The tokenizer is Unicode-aware (`\p{L}\p{N}` under the `u` modifier, not
     * `a-z0-9`) so an Arabic (or any non-Latin) query survives into $terms
     * instead of being split into nothing — previously an all-Arabic query
     * always hit the `$terms === []` early return and searched nothing.
     *
     * When the viewer is in Arabic (P2-x: same is_rtl()+locale check as
     * HelpCenterController::localizedPath()), a doc that has a users/ar/…
     * translation is searched and scored via that Arabic chunk instead of its
     * English original — but ALWAYS reported under the doc's BASE path (never
     * "…/ar/…"), so a result plugs straight into fetch_full_doc / help.show
     * exactly like an English one. A doc with no ar/ overlay (most pillars
     * have none at all) simply keeps using its English chunk — the same
     * English-fallback behavior localizedPath() applies per page. Non-Arabic
     * callers never see ar/ chunks, matching every caller's existing (English)
     * expectations bit-for-bit — this is backward-compatible for every current
     * caller (DevMcpController's search_docs tool; /help-center/search uses
     * its own separate searchPillar(), untouched here).
     *
     * @param  list<string>|null  $pillars  null = every public pillar.
     * @return list<array{doc: string, title: string, section: string, snippet: string, score: int}>
     */
    public static function search(string $query, int $maxResults = 5, ?array $pillars = null): array
    {
        $terms = array_values(array_unique(array_filter(
            preg_split('/[^\p{L}\p{N}_]+/u', mb_strtolower($query)) ?: [],
            fn ($t) => mb_strlen($t) >= 2
        )));
        if ($terms === []) {
            return [];
        }

        $preferAr = function_exists('is_rtl') && is_rtl() && app()->getLocale() === 'ar';
        $allChunks = static::chunks($pillars);

        // Which BASE doc paths have an ar/ overlay at all — only those switch
        // to Arabic content; every other doc keeps its English chunk even when
        // the viewer is in Arabic (there is nothing else to show them).
        $arBase = [];
        if ($preferAr) {
            foreach ($allChunks as $chunk) {
                if (str_contains($chunk['doc'], '/ar/')) {
                    $arBase[str_replace('/ar/', '/', $chunk['doc'])] = true;
                }
            }
        }

        $scored = [];
        foreach ($allChunks as $chunk) {
            $isAr = str_contains($chunk['doc'], '/ar/');
            $basePath = $isAr ? str_replace('/ar/', '/', $chunk['doc']) : $chunk['doc'];

            if ($preferAr) {
                if (! $isAr && isset($arBase[$basePath])) {
                    continue; // an Arabic version of this exact doc exists — use that chunk instead
                }
            } elseif ($isAr) {
                continue; // non-Arabic viewer: the ar/ overlay never surfaces
            }

            $title = mb_strtolower($chunk['title']);
            $section = mb_strtolower($chunk['section']);
            $body = mb_strtolower($chunk['text']);

            $score = 0;
            $hitTerms = 0;
            foreach ($terms as $term) {
                $hits = 0;
                $hits += substr_count($title, $term) > 0 ? 3 : 0;
                $hits += substr_count($section, $term) > 0 ? 2 : 0;
                $hits += min(substr_count($body, $term), 5);
                if ($hits > 0) {
                    $hitTerms++;
                    $score += $hits;
                }
            }
            if ($score > 0) {
                // Distinct-term coverage dominates raw frequency.
                $scored[] = ['doc' => $basePath, 'chunk' => $chunk, 'score' => $hitTerms * 100 + $score];
            }
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_map(fn ($s) => [
            'doc' => $s['doc'],
            'title' => $s['chunk']['title'],
            'section' => $s['chunk']['section'],
            'snippet' => mb_substr(trim($s['chunk']['text']), 0, 400),
            'score' => $s['score'],
        ], array_slice($scored, 0, max(1, min($maxResults, 10))));
    }
}
