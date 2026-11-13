<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Console\Commands;

use Illuminate\Console\Command;

/**
 * DOCS-PLATFORM D4-4: report documentation pages by their front-matter `updated`
 * date so stale docs are visible. `--days=N` lists only pages not verified in the
 * last N days; `--missing` lists pages with no front-matter/updated field.
 */
class DocsStaleness extends Command
{
    protected $signature = 'docs:staleness {--days=60 : flag docs whose updated date is older than this} {--missing : list only docs missing front-matter or an updated date}';

    protected $description = 'Report documentation pages by last-verified (front-matter updated) date';

    public function handle(): int
    {
        $base = resource_path('docs');
        if (! is_dir($base)) {
            $this->error("No docs directory at {$base}");

            return self::FAILURE;
        }

        $days = (int) $this->option('days');
        $missingOnly = (bool) $this->option('missing');
        $cutoff = now()->subDays($days)->format('Y-m-d');

        $rows = [];
        $missing = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'md' || $file->getFilename() === 'README.md') {
                continue;
            }
            $rel = str_replace($base.'/', '', $file->getPathname());
            // Machine-generated Liquid reference has no front-matter by design.
            if (in_array($rel, ['dev/themes/drops.md', 'dev/themes/filters.md', 'dev/themes/tags.md'], true)) {
                continue;
            }
            $meta = $this->frontMatter((string) file_get_contents($file->getPathname()));
            $updated = $meta['updated'] ?? null;

            if (! $updated) {
                $missing[] = $rel;

                continue;
            }
            $rows[] = ['doc' => $rel, 'audience' => $meta['audience'] ?? '?', 'updated' => $updated, 'stale' => $updated < $cutoff];
        }

        if ($missingOnly) {
            if ($missing === []) {
                $this->info('Every doc has front-matter with an updated date.');

                return self::SUCCESS;
            }
            $this->warn(count($missing).' docs missing front-matter/updated:');
            foreach ($missing as $m) {
                $this->line('  '.$m);
            }

            return self::SUCCESS;
        }

        usort($rows, fn ($a, $b) => strcmp($a['updated'], $b['updated']));
        $stale = array_values(array_filter($rows, fn ($r) => $r['stale']));

        $this->info(count($rows).' docs with dates; '.count($stale)." older than {$days} days (before {$cutoff}); ".count($missing).' missing front-matter.');
        $this->newLine();
        $this->table(['Doc', 'Audience', 'Updated', 'Stale'], array_map(
            fn ($r) => [$r['doc'], $r['audience'], $r['updated'], $r['stale'] ? 'STALE' : ''],
            $days > 0 ? $stale : $rows
        ));

        return self::SUCCESS;
    }

    /** @return array<string,string> */
    private function frontMatter(string $md): array
    {
        if (! str_starts_with($md, "---\n") || ($end = strpos($md, "\n---\n", 4)) === false) {
            return [];
        }
        $out = [];
        foreach (preg_split('/\R/', substr($md, 4, $end - 4)) as $line) {
            if (preg_match('/^([a-z_]+):\s*"?([^"]*)"?\s*$/i', $line, $m)) {
                $out[strtolower($m[1])] = trim($m[2]);
            }
        }

        return $out;
    }
}
