<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Scans the source for literal ___() / ___n() calls and reconciles the keys they
 * use against en/lang.php — ending the dev lang-churn where ___() auto-registers
 * missing keys on live requests ([[lang-auto-register-trap]]).
 *
 *  - Reports keys USED in source but MISSING from en/lang.php.
 *  - Reports ORPHAN en keys never seen in source (REPORT ONLY — never purged;
 *    an orphan usually means en is incomplete, not that it should shrink,
 *    [[lang-orphans-en-incomplete]]).
 *  - Counts DYNAMIC first-arg calls `___($var)` that a literal scan cannot see.
 *  - `--write` appends ONLY the missing keys to en/lang.php (direct-write idiom,
 *    value = the literal source string), exactly as ___() would auto-register.
 *
 * Exit 1 when keys are missing and --write was not given (CI-friendly gate).
 *
 * The scan ROOTS and the lang BASE are injectable via --root / --lang-base so
 * tests point them at fixture dirs and never touch the real core/lang tree.
 */
class LangScan extends Command
{
    protected $signature = 'lang:scan
        {--write : Append the missing keys to en/lang.php (en only)}
        {--root=* : Override the scan root dir(s) (repeatable; defaults to app/ + resources/views/)}
        {--lang-base= : Override the lang base dir that contains en/lang.php (default base_path(lang))}';

    protected $description = 'Reconcile ___()/___n() source keys against en/lang.php (missing, orphans, dynamic count).';

    public function handle(): int
    {
        $roots = $this->option('root') ?: [base_path('app'), base_path('resources/views')];
        $base  = (string) ($this->option('lang-base') ?: base_path('lang'));
        $write = (bool) $this->option('write');

        $res = $this->runScan($roots, $base, $write);

        $this->info(sprintf(
            'Scanned %d source key(s); %d missing from en, %d orphan en key(s), %d dynamic call site(s) skipped.',
            count($res['used']), count($res['missing']), count($res['orphans']), $res['dynamic']
        ));

        if ($res['missing']) {
            $this->newLine();
            $this->warn('Keys used in source but missing from en/lang.php:');
            foreach (array_slice(array_keys($res['missing']), 0, 50) as $k) {
                $this->line('  - '.$k);
            }
            if (count($res['missing']) > 50) {
                $this->line('  … and '.(count($res['missing']) - 50).' more.');
            }
        }

        if ($res['orphans']) {
            $this->newLine();
            $this->comment(sprintf(
                '%d orphan en key(s) not seen in source (REPORT ONLY — not purged). e.g. %s',
                count($res['orphans']),
                implode(', ', array_slice($res['orphans'], 0, 8))
            ));
        }

        $this->newLine();
        $this->line("Dynamic first-arg calls (___(\$var)) are unscannable — {$res['dynamic']} skipped.");

        if ($write && $res['written'] > 0) {
            $this->info("Wrote {$res['written']} missing key(s) into en/lang.php.");
        }

        // CI gate: fail only when there are unwritten missing keys.
        return ($res['missing'] && ! $write) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Scan the roots, reconcile against en, and (optionally) append missing keys.
     *
     * @param  list<string>  $roots
     * @return array{used: array<string,string>, missing: array<string,string>,
     *               orphans: list<string>, dynamic: int, written: int}
     */
    public function runScan(array $roots, string $base, bool $write): array
    {
        $used    = [];
        $dynamic = 0;

        foreach ($roots as $root) {
            if (! File::isDirectory($root)) {
                continue;
            }
            foreach (File::allFiles($root) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue; // .php and .blade.php both report 'php'
                }
                $src = (string) file_get_contents($file->getRealPath());

                [$fileUsed, $fileDyn] = $this->extract($src);
                $used += $fileUsed; // first-seen literal wins
                $dynamic += $fileDyn;
            }
        }

        ksort($used, SORT_STRING);

        $enMap = $this->readEn($base);

        $missing = array_diff_key($used, $enMap);            // used but not in en
        $orphans = array_keys(array_diff_key($enMap, $used)); // en but never used
        sort($orphans, SORT_STRING);

        $written = 0;
        if ($write && $missing) {
            $merged = $enMap;
            foreach ($missing as $k => $v) {
                $merged[$k] = $v;
                $written++;
            }
            $this->writeEn($base, $merged);
        }

        return [
            'used'    => $used,
            'missing' => $missing,
            'orphans' => $orphans,
            'dynamic' => $dynamic,
            'written' => $written,
        ];
    }

    /**
     * Extract literal ___()/___n() keys and count dynamic ($var) first-arg calls.
     *
     * @return array{0: array<string,string>, 1: int}
     */
    private function extract(string $src): array
    {
        $used = [];

        // ___('...') and ___n('...') — single-quoted literal first arg.
        if (preg_match_all('/(?<![A-Za-z0-9_])(___n?)\s*\(\s*\'((?:[^\'\\\\]|\\\\.)*)\'/', $src, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $lit = str_replace(["\\'", '\\\\'], ["'", '\\'], $hit[2]);
                $this->register($used, $hit[1], $lit);
            }
        }

        // ___("...") and ___n("...") — double-quoted literal first arg.
        if (preg_match_all('/(?<![A-Za-z0-9_])(___n?)\s*\(\s*"((?:[^"\\\\]|\\\\.)*)"/', $src, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $lit = stripcslashes($hit[2]);
                $this->register($used, $hit[1], $lit);
            }
        }

        // ___($var) / ___n($var) — dynamic first arg, unscannable; count only.
        $dynamic = preg_match_all('/(?<![A-Za-z0-9_])___n?\s*\(\s*\$/', $src);

        return [$used, $dynamic];
    }

    /**
     * Register a used key with the value ___()/___n() would auto-register:
     *  ___('Save')  → key 'save',   value 'Save'
     *  ___n('item') → key 'n_item', value '{1} :count item|[2,*] :count items'
     *
     * @param  array<string,string>  $used
     */
    private function register(array &$used, string $fn, string $literal): void
    {
        if ($literal === '') {
            return;
        }

        if ($fn === '___n') {
            $slug  = 'n_'.Str::slug($literal, '_');
            $value = '{1} :count '.$literal.'|[2,*] :count '.Str::plural($literal);
        } else {
            $slug  = Str::slug($literal, '_') ?: $literal;
            $value = $literal;
        }

        if (! array_key_exists($slug, $used)) {
            $used[$slug] = $value;
        }
    }

    /** @return array<string,mixed> */
    private function readEn(string $base): array
    {
        $file = $base.'/en/lang.php';
        if (! File::exists($file)) {
            return [];
        }
        $data = include $file;

        return is_array($data) ? $data : [];
    }

    /**
     * Append-merge write to en/lang.php using the EXACT ___() auto-register idiom
     * so existing rows keep byte-for-byte var_export formatting (minimal diff).
     *
     * @param  array<string,mixed>  $map
     */
    private function writeEn(string $base, array $map): void
    {
        $dir = $base.'/en';
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }
        File::put($dir.'/lang.php', "<?php\n\nreturn ".var_export($map, true).";\n");
    }
}
