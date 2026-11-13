<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services;

use Juwri\Core\Models\Language;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Lang-pipeline run — SEAM (data contract between Lane A and Lane B).
 *
 * Platform-chrome translation pipeline over core/lang/{code}/lang.php: per-key
 * PROVENANCE so "translated" is distinguishable from "___()'s auto-registered
 * English placeholder" ([[lang-auto-register-trap]]), plus coverage, search,
 * a machine-translate apply path with QA gates, and a review queue.
 *
 * Provenance sidecar: core/lang/{code}/meta.json — {key: {s, h, at, cand?, why?}}
 *   s    = status: placeholder | machine | review | human
 *   h    = sha1 of the EN source string the value was produced from
 *   at   = Y-m-d date of the last status change
 *   cand = machine candidate awaiting review (only when s=review)
 *   why  = QA failure reason for the candidate (only when s=review)
 * `en` has no sidecar — it IS the source of truth. A key present in en but
 * absent from a locale's lang.php has derived status `absent` (never stored).
 *
 * Lane A owns the internals of this class + the scan/status commands + the
 * translate job. Lane B only CONSUMES the shapes documented here and never
 * edits this file. Shape problems go to the LEDGER Open-questions table.
 *
 * The base path is injectable so tests never touch the real core/lang tree
 * (LanguagePackService precedent). Writes to lang.php go DIRECTLY (the
 * LanguageController idiom), never through ___().
 */
class LangPipelineService
{
    /** Every status keys() / coverage() can report. `absent` is derived, never stored. */
    public const STATUSES = ['absent', 'placeholder', 'machine', 'review', 'human'];

    public function __construct(private string $basePath)
    {
    }

    public static function forApp(): self
    {
        return new self(base_path('lang'));
    }

    /**
     * Cache key the chrome-translate job writes its progress under (same idiom
     * as TranslateMenuJob::progressKey). Payload shape:
     * ['status' => 'queued'|'running'|'done'|'error', 'done' => int, 'total' => int,
     *  'written' => int, 'review' => int, 'message' => string|null]
     */
    public static function progressKey(string $code): string
    {
        return 'lang_pipeline_translate_'.$code;
    }

    /**
     * Per-locale coverage summary for the dashboard.
     *
     * @return array{
     *   en_total: int,
     *   locales: list<array{code: string, direction: string, total: int,
     *     human: int, machine: int, review: int, placeholder: int, absent: int,
     *     pct_done: float}>
     * }
     * pct_done = (human + machine) / en_total * 100, 1dp; locales = every ACTIVE
     * Language except the default source locale, in Language display order.
     */
    public function coverage(): array
    {
        $source   = $this->sourceLocale();
        $enMap    = $this->readLang($source);
        $enTotal  = count($enMap);
        $locales  = [];

        foreach ($this->targetLanguages($source) as $lang) {
            $code      = (string) $lang->code;
            $localeMap = $this->readLang($code);
            $meta      = $this->readMeta($code);

            $counts = ['human' => 0, 'machine' => 0, 'review' => 0, 'placeholder' => 0, 'absent' => 0];
            foreach ($enMap as $key => $enVal) {
                $status = $this->statusFor((string) $key, $enMap, $localeMap, $meta);
                if (isset($counts[$status])) {
                    $counts[$status]++;
                }
            }

            $done     = $counts['human'] + $counts['machine'];
            $pct_done = $enTotal > 0 ? round($done / $enTotal * 100, 1) : 0.0;

            $locales[] = [
                'code'        => $code,
                'direction'   => (string) ($lang->direction ?: 'ltr'),
                'total'       => $enTotal,
                'human'       => $counts['human'],
                'machine'     => $counts['machine'],
                'review'      => $counts['review'],
                'placeholder' => $counts['placeholder'],
                'absent'      => $counts['absent'],
                'pct_done'    => $pct_done,
            ];
        }

        return ['en_total' => $enTotal, 'locales' => $locales];
    }

    /**
     * Searchable key browser for one locale.
     *
     * @return list<array{key: string, en: string, value: string|null,
     *                    status: string, candidate: string|null}>
     * $q filters key + en + value (case-insensitive substring); $status filters
     * to one of STATUSES; ordered by key; capped at $limit rows.
     */
    public function keys(string $code, ?string $q = null, ?string $status = null, int $limit = 200): array
    {
        if (! File::isDirectory($this->basePath)) {
            return [];
        }

        $source    = $this->sourceLocale();
        $enMap     = $this->readLang($source);
        $localeMap = $this->readLang($code);
        $meta      = $this->readMeta($code);

        // Union of en keys and locale keys (locale-only keys are orphans).
        $allKeys = array_keys($enMap + $localeMap);
        sort($allKeys, SORT_STRING);

        $needle = ($q !== null && $q !== '') ? mb_strtolower($q) : null;
        $rows   = [];

        foreach ($allKeys as $key) {
            $key    = (string) $key;
            $rowSt  = $this->statusFor($key, $enMap, $localeMap, $meta);
            if ($status !== null && $status !== '' && $rowSt !== $status) {
                continue;
            }

            $enVal = isset($enMap[$key]) ? (string) $enMap[$key] : '';
            $value = array_key_exists($key, $localeMap) ? (string) $localeMap[$key] : null;
            $cand  = isset($meta[$key]['cand']) ? (string) $meta[$key]['cand'] : null;

            if ($needle !== null) {
                $hay = mb_strtolower($key.' '.$enVal.' '.((string) $value));
                if (mb_strpos($hay, $needle) === false) {
                    continue;
                }
            }

            $rows[] = [
                'key'       => $key,
                'en'        => $enVal,
                'value'     => $value,
                'status'    => $rowSt,
                'candidate' => $cand,
            ];

            if (count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    /**
     * One-time heuristic bootstrap of a locale's sidecar from its current
     * lang.php: value === en source → placeholder, anything else non-empty →
     * human. Existing sidecar entries are never downgraded. Returns counts.
     *
     * @return array{placeholder: int, human: int}
     */
    public function bootstrap(string $code): array
    {
        $source = $this->sourceLocale();

        // The source locale IS the source of truth — it never gets a sidecar.
        // (Guard: a page-load bootstrap of the source must not write meta.json into
        // it, which would also break the legacy translate() view that foreach-es
        // every file in the locale dir.)
        if ($code === $source) {
            return ['placeholder' => 0, 'human' => 0];
        }

        $enMap     = $this->readLang($source);
        $localeMap = $this->readLang($code);
        $meta      = $this->readMeta($code);

        $counts  = ['placeholder' => 0, 'human' => 0];
        $changed = false;

        foreach ($localeMap as $rawKey => $val) {
            $key = (string) $rawKey;
            if (isset($meta[$key])) {
                continue; // never downgrade / never touch an existing sidecar entry
            }

            $enVal  = isset($enMap[$key]) ? (string) $enMap[$key] : '';
            $status = $this->heuristicStatus((string) $val, $enVal);

            $meta[$key] = ['s' => $status, 'h' => sha1($enVal), 'at' => $this->today()];
            $counts[$status]++;
            $changed = true;
        }

        if ($changed) {
            $this->writeMeta($code, $meta);
        }

        return $counts;
    }

    /**
     * A human saved $value for $key (the translate-editor path). Writes the
     * lang.php row + sidecar {s: human, h: sha1(en)}. Human status is FINAL:
     * no machine run may ever overwrite it (re-translate only if the en source
     * hash changes AND the owner explicitly re-opens it).
     */
    public function markHuman(string $code, string $key, string $value): void
    {
        // $key is already the canonical lang-file key (callers read it from the
        // lang/meta maps). Do NOT re-slug it: Str::slug lowercases and drops
        // trailing separators/symbols, so 'ALLOW_ADVANCED'→'allow_advanced' (case
        // collision) and 'n_'/'#'→wrong-or-empty, breaking the en lookup + write.
        $enMap = $this->readLang($this->sourceLocale());

        $localeMap       = $this->readLang($code);
        $localeMap[$key] = $value;
        $this->writeLang($code, $localeMap);

        $meta       = $this->readMeta($code);
        $meta[$key] = [
            's'  => 'human',
            'h'  => sha1((string) ($enMap[$key] ?? '')),
            'at' => $this->today(),
        ];
        $this->writeMeta($code, $meta);
    }

    /**
     * Apply machine translations for a locale. For each key: run the QA gates —
     * placeholder parity + plural-syntax parity + the translator's low-confidence
     * flag. PASS → write lang.php row + sidecar {s: machine}. FAIL → leave
     * lang.php untouched, store sidecar {s: review, cand, why}. Keys whose
     * sidecar says human are skipped unconditionally.
     *
     * @param  array<string, array{value: string, lowconf?: bool}>  $translations  key => machine output
     * @return array{written: int, queued_review: int, skipped_human: int}
     */
    public function applyMachine(string $code, array $translations): array
    {
        $enMap     = $this->readLang($this->sourceLocale());
        $localeMap = $this->readLang($code);
        $meta      = $this->readMeta($code);

        $out = ['written' => 0, 'queued_review' => 0, 'skipped_human' => 0];

        foreach ($translations as $rawKey => $t) {
            // Canonical key as-is (from keys()/the job) — never re-slug (see markHuman).
            $key = (string) $rawKey;

            if (($meta[$key]['s'] ?? null) === 'human') {
                $out['skipped_human']++;
                continue;
            }

            $enVal   = (string) ($enMap[$key] ?? '');
            $h       = sha1($enVal);
            $value   = (string) $t['value'];
            $lowconf = (bool) ($t['lowconf'] ?? false);

            $why = null;
            if (! self::qaPlaceholders($enVal, $value)) {
                $why = 'placeholders';
            } elseif (str_contains($enVal, '|') && ! self::qaPlural($enVal, $value)) {
                $why = 'plural';
            } elseif ($lowconf) {
                $why = 'lowconf';
            }

            if ($why === null) {
                $localeMap[$key] = $value;
                $meta[$key]      = ['s' => 'machine', 'h' => $h, 'at' => $this->today()];
                $out['written']++;
            } else {
                $meta[$key] = ['s' => 'review', 'cand' => $value, 'why' => $why, 'h' => $h, 'at' => $this->today()];
                $out['queued_review']++;
            }
        }

        $this->writeLang($code, $localeMap);
        $this->writeMeta($code, $meta);

        return $out;
    }

    /**
     * Keys awaiting human review for a locale (status = review).
     *
     * @return list<array{key: string, en: string, value: string|null,
     *                    candidate: string, why: string}>
     */
    public function reviewQueue(string $code): array
    {
        $enMap     = $this->readLang($this->sourceLocale());
        $localeMap = $this->readLang($code);
        $meta      = $this->readMeta($code);

        $rows = [];
        foreach ($meta as $rawKey => $m) {
            if (($m['s'] ?? null) !== 'review') {
                continue;
            }
            $key    = (string) $rawKey;
            $rows[] = [
                'key'       => $key,
                'en'        => (string) ($enMap[$key] ?? ''),
                'value'     => array_key_exists($key, $localeMap) ? (string) $localeMap[$key] : null,
                'candidate' => (string) ($m['cand'] ?? ''),
                'why'       => (string) ($m['why'] ?? ''),
            ];
        }

        usort($rows, static fn ($a, $b) => strcmp($a['key'], $b['key']));

        return $rows;
    }

    /** Approve the stored candidate: write it to lang.php, sidecar → machine. */
    public function approveCandidate(string $code, string $key): void
    {
        // Raw canonical key (from the review queue) — never re-slug (see markHuman).
        $meta = $this->readMeta($code);

        if (($meta[$key]['s'] ?? null) !== 'review') {
            return;
        }

        $cand = (string) ($meta[$key]['cand'] ?? '');
        $h    = (string) ($meta[$key]['h'] ?? sha1((string) ($this->readLang($this->sourceLocale())[$key] ?? '')));

        $localeMap       = $this->readLang($code);
        $localeMap[$key] = $cand;
        $this->writeLang($code, $localeMap);

        $meta[$key] = ['s' => 'machine', 'h' => $h, 'at' => $this->today()];
        $this->writeMeta($code, $meta);
    }

    /** Reject the candidate: drop it, sidecar → placeholder (re-translatable). */
    public function rejectCandidate(string $code, string $key): void
    {
        // Raw canonical key (from the review queue) — never re-slug (see markHuman).
        $meta = $this->readMeta($code);

        if (($meta[$key]['s'] ?? null) !== 'review') {
            return;
        }

        $h = (string) ($meta[$key]['h'] ?? sha1((string) ($this->readLang($this->sourceLocale())[$key] ?? '')));

        $meta[$key] = ['s' => 'placeholder', 'h' => $h, 'at' => $this->today()];
        $this->writeMeta($code, $meta);
    }

    /**
     * QA gate: every Laravel :placeholder token in $en must appear exactly in
     * $out (set equality — order free, none added, none lost).
     */
    public static function qaPlaceholders(string $en, string $out): bool
    {
        $tokens = static function (string $s): array {
            preg_match_all('/(?<![a-zA-Z0-9_]):([a-zA-Z_][a-zA-Z0-9_]*)/', $s, $m);
            $t = $m[1];
            sort($t, SORT_STRING);

            return $t;
        };

        return $tokens($en) === $tokens($out);
    }

    /**
     * QA gate for ___n() plural values: pipe count and every {n}/[n,m]/[n,*]
     * range marker present in $en must survive in $out.
     */
    public static function qaPlural(string $en, string $out): bool
    {
        if (! str_contains($en, '|')) {
            return true; // only meaningful for plural (piped) source strings
        }

        if (substr_count($en, '|') !== substr_count($out, '|')) {
            return false;
        }

        $markers = static function (string $s): array {
            preg_match_all('/\{\d+\}|\[\d+,\s*(?:\d+|\*)\]/', $s, $m);
            $t = $m[0];
            sort($t, SORT_STRING);

            return $t;
        };

        return $markers($en) === $markers($out);
    }

    // ── internals ─────────────────────────────────────────────────────────────

    /** Default/source locale — its lang.php is the source of truth, no sidecar. */
    private function sourceLocale(): string
    {
        return (string) config('settings.lang', config('app.default_language', 'en'));
    }

    /**
     * Active languages except the source locale, in Language display order.
     *
     * @return \Illuminate\Support\Collection<int, \Juwri\Core\Models\Language>
     */
    private function targetLanguages(string $source): \Illuminate\Support\Collection
    {
        return Language::query()
            ->where('active', 1)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->reject(fn ($l) => (string) $l->code === $source)
            ->values();
    }

    /**
     * Derived status for a key: sidecar wins, else the bootstrap heuristic.
     *
     * @param  array<string,mixed>  $enMap
     * @param  array<string,mixed>  $localeMap
     * @param  array<string,array<string,mixed>>  $meta
     */
    private function statusFor(string $key, array $enMap, array $localeMap, array $meta): string
    {
        if (isset($meta[$key]['s'])) {
            return (string) $meta[$key]['s'];
        }
        if (! array_key_exists($key, $localeMap)) {
            return 'absent';
        }

        return $this->heuristicStatus((string) $localeMap[$key], (string) ($enMap[$key] ?? ''));
    }

    /** Two-branch bootstrap heuristic (empty/en-equal → placeholder, else human). */
    private function heuristicStatus(string $val, string $enVal): string
    {
        if (trim($val) === '' || $val === $enVal) {
            return 'placeholder';
        }

        return 'human';
    }

    private function today(): string
    {
        return date('Y-m-d');
    }

    /**
     * Read one locale's lang.php map (missing/invalid → empty map).
     *
     * @return array<string,mixed>
     */
    private function readLang(string $code): array
    {
        $file = $this->basePath.'/'.$code.'/lang.php';
        if (! File::exists($file)) {
            return [];
        }
        $data = include $file;

        return is_array($data) ? $data : [];
    }

    /**
     * Direct file write (the LanguageController::translateUpdate idiom).
     *
     * @param  array<string,mixed>  $map
     */
    private function writeLang(string $code, array $map): void
    {
        $dir = $this->basePath.'/'.$code;
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }
        File::put($dir.'/lang.php', "<?php \n return ".var_export($map, true).";\n");
    }

    /**
     * Tolerant sidecar read: missing file or corrupt JSON yields [].
     *
     * @return array<string,array<string,mixed>>
     */
    private function readMeta(string $code): array
    {
        $file = $this->basePath.'/'.$code.'/meta.json';
        if (! File::exists($file)) {
            return [];
        }
        $decoded = json_decode((string) File::get($file), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Pretty-printed sidecar write so diffs review cleanly.
     *
     * @param  array<string,array<string,mixed>>  $meta
     */
    private function writeMeta(string $code, array $meta): void
    {
        $dir = $this->basePath.'/'.$code;
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }
        File::put(
            $dir.'/meta.json',
            json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }
}
