<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services\Backup;

/**
 * Memory-safe, one-row-at-a-time writer for a single export dataset (WI-B1/W0-2).
 *
 * A dataset is streamed straight to its file(s): the JSON array is opened with
 * `[`, each row is appended as it arrives (comma-separated, NOT pretty-printed so
 * the string never doubles in size), and the array is closed with `]`. CSV is
 * written header-then-row. Nothing holds the whole result set, its array copy,
 * and the encoded string in memory at once, so peak memory is O(one row) instead
 * of O(rows) — a large store no longer OOMs mid-build.
 *
 * Every CSV cell is passed through the formula-injection neutralizer (WI-A3/W0-3)
 * before it is written, so an attacker-controlled storefront string like
 * `=HYPERLINK(...)` can never execute when the owner opens the file in Excel or
 * Sheets. The column set is taken from the first row — every row of a single DB
 * table shares the same columns, so one pass is enough.
 */
class DatasetStreamWriter
{
    /** @var resource|null */
    private $jsonH = null;
    /** @var resource|null */
    private $csvH = null;
    private array $cols = [];
    private bool $started = false;
    private int $count = 0;

    public function __construct(string $work, string $file, bool $json, bool $csv)
    {
        if ($json) {
            $this->jsonH = fopen($work . '/' . $file . '.json', 'w');
            fwrite($this->jsonH, '[');
        }
        if ($csv) {
            $this->csvH = fopen($work . '/' . $file . '.csv', 'w');
            fwrite($this->csvH, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
        }
    }

    /** Append one already-scrubbed row. Columns are fixed from the first row. */
    public function write(array $row): void
    {
        if (! $this->started) {
            $this->cols = array_keys($row);
            if ($this->csvH) {
                fputcsv($this->csvH, array_map(fn ($c) => self::csvCell($c), $this->cols), escape: '\\');
            }
        }

        if ($this->jsonH) {
            fwrite($this->jsonH, ($this->count ? ',' : '') . json_encode(
                $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));
        }
        if ($this->csvH) {
            $line = [];
            foreach ($this->cols as $c) {
                $line[] = self::csvCell($row[$c] ?? '');
            }
            fputcsv($this->csvH, $line, escape: '\\');
        }

        $this->started = true;
        $this->count++;
    }

    /** Close the file(s) and return the row count. */
    public function close(): int
    {
        if ($this->jsonH) {
            fwrite($this->jsonH, ']');
            fclose($this->jsonH);
            $this->jsonH = null;
        }
        if ($this->csvH) {
            if (! $this->started) {
                fputcsv($this->csvH, ['(empty)'], escape: '\\'); // header-less placeholder for an empty set
            }
            fclose($this->csvH);
            $this->csvH = null;
        }

        return $this->count;
    }

    private const TRIGGERS = ['=', '+', '-', '@', "\t", "\r", "\n", '＝', '＋', '－', '＠'];

    /**
     * Neutralize one CSV cell against spreadsheet formula injection (OWASP, list
     * updated Aug 2025 to add LF). A cell is dangerous when its first character
     * is one of `= + - @`, a leading TAB/CR/LF, or a full-width homoglyph
     * `＝ ＋ － ＠`; we prefix such a cell with a single quote — the most robust
     * mitigation across Excel / Sheets / LibreOffice.
     */
    public static function csvCell($v): string
    {
        if (is_array($v) || is_object($v)) {
            $v = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif ($v === null) {
            return '';
        } elseif (is_bool($v)) {
            return $v ? '1' : '';
        }

        $s = (string) $v;
        if ($s === '') {
            return $s;
        }

        $first = mb_substr($s, 0, 1, 'UTF-8');
        if (in_array($first, self::TRIGGERS, true)) {
            return "'" . $s;
        }

        return $s;
    }

    /**
     * Neutralize a whole CSV row. Real ints/floats pass through untouched (a negative
     * number is data, not a formula); every other value goes through csvCell().
     *
     * @param  array<int|string, mixed>  $row
     * @return array<int|string, mixed>
     */
    public static function csvRow(array $row): array
    {
        return array_map(fn ($v) => is_int($v) || is_float($v) ? $v : self::csvCell($v), $row);
    }

    /**
     * Inverse of csvCell() for importers: strip ONE leading `'` when it is followed by a trigger
     * character, so an exported "'-20% Deal" re-imports as "-20% Deal". Other values are unchanged.
     */
    public static function csvUncell(mixed $v): mixed
    {
        if (! is_string($v) || mb_substr($v, 0, 1, 'UTF-8') !== "'") {
            return $v;
        }
        $second = mb_substr($v, 1, 1, 'UTF-8');

        return in_array($second, self::TRIGGERS, true) ? mb_substr($v, 1, null, 'UTF-8') : $v;
    }
}
