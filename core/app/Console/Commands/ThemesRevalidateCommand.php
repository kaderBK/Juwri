<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Console\Commands;

use App\Models\ThemeVersion;
use Illuminate\Console\Command;
use Juwri\Modules\Storefront\Storefront\ThemePackageValidator;

/**
 * P6-263: re-run the CURRENT markup rules over every theme already on disk (seller uploads in
 * storage/app/marketplace-themes and in-repo reference themes). Versions installed before a rule existed are
 * flagged; `--delist` also takes a flagged PUBLISHED marketplace version off storefronts (status → delisted; the
 * only change a published version allows). Live renders are protected either way by ThemeOutputSanitizer.
 * Exit code 1 when anything is flagged.
 */
class ThemesRevalidateCommand extends Command
{
    protected $signature = 'themes:revalidate {--delist : Delist flagged published marketplace versions}';

    protected $description = 'Re-validate installed themes against the current theme markup rules (P6-263)';

    public function handle(): int
    {
        $flagged = 0;
        foreach ([storage_path('app/marketplace-themes') => true, resource_path('liquid-themes') => false] as $base => $marketplace) {
            foreach (glob($base.'/*/*', GLOB_ONLYDIR) ?: [] as $dir) {
                $issues = self::scan($dir);
                if ($issues === []) {
                    continue;
                }
                $flagged++;
                $slug = basename(dirname($dir));
                $version = basename($dir);
                $this->error("FLAGGED {$slug}@{$version}");
                foreach ($issues as $issue) {
                    $this->line('  - '.$issue);
                }

                if ($marketplace && $this->option('delist')) {
                    $row = ThemeVersion::query()
                        ->whereHas('theme', fn ($q) => $q->where('slug', strtolower($slug)))
                        ->where('semver', $version)
                        ->where('status', 'published')
                        ->first();
                    if ($row) {
                        $row->update(['status' => 'delisted']);
                        $this->warn("  delisted {$slug}@{$version}");
                    }
                }
            }
        }

        $this->info($flagged === 0 ? 'All installed themes pass the current rules.' : "{$flagged} theme version(s) flagged.");

        return $flagged === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<string> */
    public static function scan(string $dir): array
    {
        $issues = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            $ext = strtolower($file->getExtension());
            if (! in_array($ext, ['liquid', 'svg'], true)) {
                continue;
            }
            $rel = ltrim(substr($file->getPathname(), strlen($dir)), '/');
            foreach (ThemePackageValidator::markupIssues($rel, (string) file_get_contents($file->getPathname())) as $msg) {
                $issues[] = $msg;
            }
        }

        return $issues;
    }
}
