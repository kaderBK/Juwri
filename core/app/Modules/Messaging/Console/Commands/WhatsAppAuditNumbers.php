<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Console\Commands;

use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * WhatsApp number audit — READ-ONLY.
 *
 * Answers the open question recorded in rev/whatsapp-consolidation/STATUS.md:
 * how many stored numbers are broken, and in which way. Scans branches.whatsapp
 * and posts.phone for values that cannot be dialled by wa.me as stored:
 *
 *   mojibake   — double-encoded text (UTF-8 read as Latin-1 and re-encoded),
 *                the state stores 129/962 were repaired from by hand and store
 *                777 was later found still in. Presents as Ø/Ù/Ã artifact bytes.
 *   empty      — whatsapp_normalize_number() yields nothing (letters, dots,
 *                Arabic words — 8 known junk rows).
 *   multiple   — two or more embedded phone-length digit runs in one field
 *                ("واتساب 05… اتصال 05…"); wa.me would be handed a concatenation.
 *   too-long   — normalization exceeds 15 digits (E.164 ceiling), usually the
 *                concatenation case or pasted garbage.
 *   leading-0  — normalizes to a national 0 prefix, which wa.me cannot dial
 *                (~637 known). S3 validation blocks NEW values like this but
 *                deliberately grandfathers what was already stored.
 *
 * This command NEVER writes to the numbers. Auto-repairing mojibake is a guess
 * the code deliberately does not make (decision recorded in STATUS.md) — the
 * output is a worklist for manual repair or a targeted merchant email.
 */
class WhatsAppAuditNumbers extends Command
{
    protected $signature = 'whatsapp:audit-numbers
        {--csv= : Also write the full result as CSV to the given path}
        {--class= : Only report one problem class (mojibake, empty, multiple, too-long, leading-0)}';

    protected $description = 'Report undiallable WhatsApp/phone numbers (mojibake, junk, multiples, leading zeros). Read-only.';

    private const CLASSES = ['mojibake', 'empty', 'multiple', 'too-long', 'leading-0'];

    /**
     * A digit run at least this long counts as an embedded phone number when
     * looking for several numbers pasted into one field.
     */
    private const PHONE_RUN = 8;

    /** E.164 maximum. */
    private const MAX_DIGITS = 15;

    public function handle(): int
    {
        $onlyClass = $this->option('class');
        if ($onlyClass && ! in_array($onlyClass, self::CLASSES, true)) {
            $this->error('Unknown class: ' . $onlyClass . '. Known: ' . implode(', ', self::CLASSES));

            return self::FAILURE;
        }

        $rows = [];

        // Branch numbers — the canonical WhatsApp location since WA-D2/E3.
        Branch::query()
            ->whereNotNull('whatsapp')->where('whatsapp', '!=', '')
            ->orderBy('store_id')->orderBy('position')->orderBy('id')
            ->each(function (Branch $b) use (&$rows) {
                foreach ($this->classify($b->whatsapp) as $class) {
                    $rows[] = $this->row('branch', $b->store_id, $b->id, $b->whatsapp, $class);
                }
            });

        // Store phones — the storefront_whatsapp_number() fallback of last
        // resort, so a broken value here goes live the moment a branch empties.
        Store::query()
            ->whereNotNull('phone')->where('phone', '!=', '')
            ->orderBy('id')
            ->each(function (Store $p) use (&$rows) {
                foreach ($this->classify($p->phone) as $class) {
                    $rows[] = $this->row('post.phone', $p->id, null, $p->phone, $class);
                }
            });

        if ($onlyClass) {
            $rows = array_values(array_filter($rows, fn ($r) => $r['problem'] === $onlyClass));
        }

        if (! $rows) {
            $this->info('No problem numbers found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Source', 'Store', 'Branch', 'Raw value', 'Normalized', 'Problem'],
            array_map(fn ($r) => [
                $r['source'],
                $r['store_id'],
                $r['branch_id'] ?? '—',
                // Raw values can be long garbage — keep the table readable.
                mb_strimwidth($r['raw'], 0, 44, '…'),
                $r['normalized'] !== '' ? $r['normalized'] : '—',
                $r['problem'],
            ], $rows)
        );

        $byClass = [];
        foreach ($rows as $r) {
            $byClass[$r['problem']] = ($byClass[$r['problem']] ?? 0) + 1;
        }
        ksort($byClass);
        $this->line('');
        $this->info(count($rows) . ' problem rows across '
            . count(array_unique(array_column($rows, 'store_id'))) . ' stores:');
        foreach ($byClass as $class => $n) {
            $this->line(sprintf('  %-10s %d', $class, $n));
        }
        $this->line('');
        $this->comment('Read-only report. Nothing was changed — repairs are manual by design.');

        if ($csv = $this->option('csv')) {
            $this->writeCsv($csv, $rows);
        }

        return self::SUCCESS;
    }

    /**
     * Every problem class a raw value falls into (a value can be in several —
     * store 777 is mojibake + multiple + leading-0 at once).
     *
     * @return string[]
     */
    private function classify(?string $raw): array
    {
        $raw = (string) $raw;
        $normalized = whatsapp_normalize_number($raw);
        $classes = [];

        // Double-encoding artifacts: UTF-8 Arabic read as Latin-1 re-encodes
        // ب/ت/و… into Ø/Ù pairs (Ã for Latin accents). Legitimate numbers never
        // contain these codepoints.
        if (preg_match('/[\x{00C3}\x{00D8}\x{00D9}\x{00DA}\x{00DB}]/u', $raw)) {
            $classes[] = 'mojibake';
        }

        if ($normalized === '') {
            $classes[] = 'empty';

            return $classes; // nothing below applies to an empty normalization
        }

        // Several phone-length digit runs pasted into one field. Count runs on
        // the latin-digit form so Arabic-numeral halves are seen too.
        if (preg_match_all('/\d{' . self::PHONE_RUN . ',}/', whatsapp_latin_digits($raw), $m) && count($m[0]) >= 2) {
            $classes[] = 'multiple';
        }

        if (strlen($normalized) > self::MAX_DIGITS) {
            $classes[] = 'too-long';
        }

        if ($normalized[0] === '0') {
            $classes[] = 'leading-0';
        }

        return $classes;
    }

    private function row(string $source, int $storeId, ?int $branchId, string $raw, string $class): array
    {
        return [
            'source' => $source,
            'store_id' => $storeId,
            'branch_id' => $branchId,
            'raw' => $raw,
            'normalized' => whatsapp_normalize_number($raw),
            'problem' => $class,
        ];
    }

    private function writeCsv(string $path, array $rows): void
    {
        // Mirrors WhatsAppMigrateLegacy::writeReport(): never let the test
        // suite write artifacts outside its sandbox.
        if (app()->runningUnitTests()) {
            return;
        }

        $fh = fopen($path, 'w');
        if (! $fh) {
            $this->error('Could not open ' . $path . ' for writing.');

            return;
        }

        // BOM so Excel opens the Arabic raw values as UTF-8.
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['source', 'store_id', 'branch_id', 'raw', 'normalized', 'problem'], escape: '\\');
        foreach ($rows as $r) {
            fputcsv($fh, [$r['source'], $r['store_id'], $r['branch_id'], $r['raw'], $r['normalized'], $r['problem']], escape: '\\');
        }
        fclose($fh);

        $this->info('CSV written to ' . (realpath($path) ?: $path));
    }
}
