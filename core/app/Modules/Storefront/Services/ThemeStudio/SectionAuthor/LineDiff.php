<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Services\ThemeStudio\SectionAuthor;

/**
 * Theme Studio phase E1: a small line diff for the review page (what the
 * PR adds, and what each repair round changed). LCS over lines, capped so a
 * pathological answer can't blow up the page.
 */
class LineDiff
{
    public const MAX_LINES = 400;

    /**
     * @return list<array{op:string,text:string}> op: ' ' same, '+' added, '-' removed
     */
    public static function lines(string $old, string $new): array
    {
        $a = $old === '' ? [] : array_slice(explode("\n", str_replace("\r\n", "\n", $old)), 0, self::MAX_LINES);
        $b = $new === '' ? [] : array_slice(explode("\n", str_replace("\r\n", "\n", $new)), 0, self::MAX_LINES);
        $n = count($a);
        $m = count($b);

        // LCS lengths (suffix table).
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        $out = [];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $out[] = ['op' => ' ', 'text' => $a[$i]];
                $i++;
                $j++;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $out[] = ['op' => '-', 'text' => $a[$i++]];
            } else {
                $out[] = ['op' => '+', 'text' => $b[$j++]];
            }
        }
        while ($i < $n) {
            $out[] = ['op' => '-', 'text' => $a[$i++]];
        }
        while ($j < $m) {
            $out[] = ['op' => '+', 'text' => $b[$j++]];
        }

        return $out;
    }

    /** Count of changed lines (+ and -). */
    public static function changes(string $old, string $new): int
    {
        return count(array_filter(self::lines($old, $new), fn (array $l) => $l['op'] !== ' '));
    }
}
