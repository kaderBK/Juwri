<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Support;

/** Test helper: look inside a TCPDF file without a PDF reader. */
final class PdfInspector
{
    /**
     * Number of filled rectangles ("re" operators) in all content streams. A TCPDF-drawn QR code is a few hundred of
     * them; a page of plain text and table rules has a handful.
     */
    public static function rectangles(string $pdf): int
    {
        $count = 0;
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m);
        foreach ($m[1] as $raw) {
            $data = @gzuncompress($raw);
            if ($data !== false) {
                // four numeric operands before "re": binary font streams that inflate to something never match this
                $count += preg_match_all('/(?<![\d.])-?[\d.]+ -?[\d.]+ -?[\d.]+ -?[\d.]+ re\b/', $data);
            }
        }

        return $count;
    }

    /**
     * The QR modules on the page as horizontal runs: every rectangle whose height is the (most common) small module
     * height. White backing squares, rules and text boxes have other heights.
     *
     * @return array{0: float, 1: list<array{0: float, 1: float, 2: float}>}|null [cell, [[x, topY, width]]] in PDF points
     */
    private static function qrRuns(string $pdf): ?array
    {
        $rects = [];
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $m);
        foreach ($m[1] as $raw) {
            $data = @gzuncompress($raw);
            if ($data === false || preg_match_all('/(?<![\d.])(-?[\d.]+) (-?[\d.]+) (-?[\d.]+) (-?[\d.]+) re\b/', $data, $r, PREG_SET_ORDER) < 20) {
                continue;
            }
            foreach ($r as [, $x, $y, $w, $h]) {
                if (abs((float) $h) <= 5 && abs((float) $h) > 0.05) {
                    $rects[] = [(float) $x, max((float) $y, (float) $y + (float) $h), abs((float) $w), round(abs((float) $h), 2)];
                }
            }
        }
        if ($rects === []) {
            return null;
        }
        $counts = array_count_values(array_map(fn ($r) => (string) $r[3], $rects));
        arsort($counts);
        $cell = (float) array_key_first($counts);
        $runs = [];
        foreach ($rects as [$x, $top, $w, $h]) {
            if (abs($h - $cell) <= 0.011) {
                $runs[] = [$x, $top, $w];
            }
        }

        return [$cell, $runs];
    }

    /**
     * Bounding box in PDF points (origin bottom-left) of the QR modules: [minX, minY, maxX, maxY].
     *
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    public static function rectangleBounds(string $pdf): ?array
    {
        $found = self::qrRuns($pdf);
        if ($found === null) {
            return null;
        }
        [$cell, $runs] = $found;

        return [
            min(array_column($runs, 0)),
            min(array_column($runs, 1)) - $cell,
            max(array_map(fn ($r) => $r[0] + $r[2], $runs)),
            max(array_column($runs, 1)),
        ];
    }

    /**
     * The QR module matrix as drawn on the page (runs expanded to modules); the grid comes from the module size and the
     * extent of the code (a QR code always has dark modules at its left, right and top edges).
     *
     * @return array<int, array<int, int>>|null
     */
    public static function drawnQrMatrix(string $pdf): ?array
    {
        $found = self::qrRuns($pdf);
        if ($found === null) {
            return null;
        }
        [$cell, $runs] = $found;
        $minX = min(array_column($runs, 0));
        $maxX = max(array_map(fn ($r) => $r[0] + $r[2], $runs));
        $top = max(array_column($runs, 1));
        $n = (int) round(($maxX - $minX) / $cell);
        $grid = array_fill(0, $n, array_fill(0, $n, 0));
        foreach ($runs as [$x, $y, $w]) {
            $row = (int) round(($top - $y) / $cell);
            $col = (int) round(($x - $minX) / $cell);
            for ($i = 0, $len = (int) round($w / $cell); $i < $len; $i++) {
                if ($row < $n && $col + $i < $n) {
                    $grid[$row][$col + $i] = 1;
                }
            }
        }

        return $grid;
    }
}
