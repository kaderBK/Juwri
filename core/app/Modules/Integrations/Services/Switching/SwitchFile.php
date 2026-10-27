<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Integrations\Services\Switching;

use Illuminate\Support\Str;
use ZipArchive;

/**
 * P4-22: a switching file (CSV or Excel .xlsx) as a header row + data rows of strings. No third-party reader: CSV
 * (comma / semicolon / tab, UTF-8 with or without BOM, UTF-16 with BOM) and the first worksheet of an .xlsx (shared and
 * inline strings, numbers, booleans) are read here, with hard limits so a crafted file cannot exhaust the worker.
 */
final class SwitchFile
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    public const MAX_ROWS = 5000;

    /** The largest uncompressed XML part read from an .xlsx (a zip bomb stops here). */
    public const MAX_XML_BYTES = 40 * 1024 * 1024;

    /** @return array{header: list<string>, rows: list<list<string>>} */
    public static function read(string $bytes, string $extension): array
    {
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new SwitchError(___('The file is larger than 5 MB.'));
        }
        $table = strtolower($extension) === 'xlsx' ? self::xlsx($bytes) : self::csv($bytes);
        $table = array_values(array_filter($table, fn (array $r) => implode('', array_map('trim', $r)) !== ''));
        if (count($table) < 2) {
            throw new SwitchError(___('The file has no data rows.'));
        }
        if (count($table) - 1 > self::MAX_ROWS) {
            throw new SwitchError(___('The file has more than :n rows; split it into smaller files.', ['n' => self::MAX_ROWS]));
        }
        $header = array_map(fn ($h) => trim((string) $h), array_shift($table));

        return ['header' => $header, 'rows' => array_map(fn (array $r) => array_map(fn ($c) => trim((string) $c), $r), $table)];
    }

    /** @return list<list<string>> */
    private static function csv(string $bytes): array
    {
        if (str_starts_with($bytes, "\xFF\xFE") || str_starts_with($bytes, "\xFE\xFF")) {
            $bytes = (string) mb_convert_encoding(substr($bytes, 2), 'UTF-8', str_starts_with($bytes, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE');
        }
        $bytes = (string) preg_replace('/^\xEF\xBB\xBF/', '', $bytes);
        if (! mb_check_encoding($bytes, 'UTF-8')) {
            throw new SwitchError(___('The file is not UTF-8 text. In Excel choose "CSV UTF-8" when saving, or upload the .xlsx file.'));
        }
        $first = strtok($bytes, "\r\n") ?: '';
        $delimiter = collect([',', ';', "\t"])->sortByDesc(fn ($d) => substr_count($first, $d))->first();

        $out = [];
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $bytes);
        rewind($fh);
        while (($row = fgetcsv($fh, 0, $delimiter, '"', '')) !== false) {
            $out[] = array_map(fn ($c) => (string) $c, $row);
            if (count($out) > self::MAX_ROWS + 1) {
                break;
            }
        }
        fclose($fh);

        return $out;
    }

    /** @return list<list<string>> the first worksheet */
    private static function xlsx(string $bytes): array
    {
        // A random name in the app's own storage (ZipArchive needs a path); removed in finally.
        $dir = storage_path('framework/cache');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $tmp = $dir.'/swx-'.Str::random(40).'.xlsx';
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive;
        try {
            if ($zip->open($tmp) !== true) {
                throw new SwitchError(___('The Excel file could not be opened.'));
            }
            $part = function (string $name) use ($zip): ?string {
                $stat = $zip->statName($name);
                if ($stat === false) {
                    return null;
                }
                if ($stat['size'] > self::MAX_XML_BYTES) {
                    throw new SwitchError(___('The Excel file is too large to read.'));
                }

                return (string) $zip->getFromName($name);
            };
            $sheet = self::firstSheetPath($part) ?? 'xl/worksheets/sheet1.xml';
            $strings = [];
            if (($ss = $part('xl/sharedStrings.xml')) !== null) {
                $xml = self::xml($ss);
                foreach ($xml->si as $si) {
                    $strings[] = self::text($si);
                }
            }
            $sheetXml = $part($sheet);
            if ($sheetXml === null) {
                throw new SwitchError(___('The Excel file has no worksheet.'));
            }
            $rows = [];
            foreach (self::xml($sheetXml)->sheetData->row as $row) {
                $cells = [];
                foreach ($row->c as $c) {
                    $idx = self::column((string) $c['r']) ?? count($cells);
                    $type = (string) $c['t'];
                    $value = match ($type) {
                        's' => $strings[(int) $c->v] ?? '',
                        'inlineStr' => self::text($c->is),
                        'b' => (string) $c->v === '1' ? '1' : '0',
                        'str', 'e' => (string) $c->v,
                        default => self::number((string) $c->v),
                    };
                    $cells[$idx] = $value;
                }
                $line = [];
                for ($i = 0, $n = $cells === [] ? 0 : max(array_keys($cells)) + 1; $i < $n; $i++) {
                    $line[] = $cells[$i] ?? '';
                }
                $rows[] = $line;
                if (count($rows) > self::MAX_ROWS + 1) {
                    break;
                }
            }

            return $rows;
        } finally {
            $zip->close();
            @unlink($tmp);
        }
    }

    private static function firstSheetPath(callable $part): ?string
    {
        $wb = $part('xl/workbook.xml');
        $rels = $part('xl/_rels/workbook.xml.rels');
        if ($wb === null || $rels === null) {
            return null;
        }
        $book = self::xml($wb);
        $first = $book->sheets->sheet[0] ?? null;
        if ($first === null) {
            return null;
        }
        $rid = (string) ($first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '');
        foreach (self::xml($rels)->Relationship as $r) {
            if ((string) $r['Id'] === $rid) {
                $target = ltrim((string) $r['Target'], '/');

                return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
            }
        }

        return null;
    }

    private static function xml(string $xml): \SimpleXMLElement
    {
        // No DTDs or external entities (XXE): LIBXML_NONET, and a DOCTYPE is refused outright.
        if (stripos($xml, '<!DOCTYPE') !== false) {
            throw new SwitchError(___('The Excel file could not be read.'));
        }
        $el = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        if ($el === false) {
            throw new SwitchError(___('The Excel file could not be read.'));
        }

        return $el;
    }

    private static function text(?\SimpleXMLElement $node): string
    {
        if ($node === null) {
            return '';
        }
        if (isset($node->t)) {
            return (string) $node->t;
        }
        $out = '';
        foreach ($node->r as $run) {
            $out .= (string) $run->t;
        }

        return $out;
    }

    /** "C12" → 2 */
    private static function column(string $ref): ?int
    {
        if (! preg_match('/^([A-Z]+)\d+$/', $ref, $m)) {
            return null;
        }
        $n = 0;
        foreach (str_split($m[1]) as $ch) {
            $n = $n * 26 + (ord($ch) - 64);
        }

        return $n - 1;
    }

    /** A numeric cell as Excel stored it (binary float): 12.499999999999998 → "12.5"; integers stay integers. */
    private static function number(string $v): string
    {
        if ($v === '' || ! is_numeric($v)) {
            return $v;
        }
        if (! str_contains($v, '.') && ! str_contains(strtolower($v), 'e')) {
            return $v;
        }

        return rtrim(rtrim(sprintf('%.6F', (float) $v), '0'), '.');
    }
}
