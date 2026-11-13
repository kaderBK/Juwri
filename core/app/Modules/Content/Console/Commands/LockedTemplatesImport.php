<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Console\Commands;

use Juwri\Modules\Content\Models\LockedTemplate;
use Juwri\Modules\Content\Models\LockedTemplateVersion;
use Juwri\Modules\Content\Services\LockedTemplates\Manifest;
use Illuminate\Console\Command;

/**
 * Import locked-template manifests (core/database/locked-templates/<key>/v<N>.json, with
 * the files under assets/locked-templates/<key>/v<N>/) into the database. A sibling
 * lib-v<N>.json, when present, is that version's section library. juwri ships one
 * open sample (`starter`); other templates are an add-on installed the same way.
 *
 * Idempotent: an existing (template, version) is left alone unless --force, because
 * published versions are immutable — pages pinned to them must not change underneath.
 */
class LockedTemplatesImport extends Command
{
    protected $signature = 'locked-templates:import
        {path? : a manifest file or a directory (default: database/locked-templates)}
        {--publish : mark imported templates published and make the imported version current}
        {--force : overwrite an existing version\'s manifest (dev only — pinned pages change)}';

    protected $description = 'Import converted locked-template manifests into the database';

    public function handle(): int
    {
        $path = (string) ($this->argument('path') ?: database_path('locked-templates'));
        $files = is_dir($path) ? glob(rtrim($path, '/').'/*/v*.json') : [$path];
        if (! $files) {
            $this->warn("No manifests found under {$path}");

            return self::SUCCESS;
        }

        $failed = 0;
        foreach ($files as $file) {
            $failed += $this->importFile($file) ? 0 : 1;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function importFile(string $file): bool
    {
        $json = @file_get_contents($file);
        $m = is_string($json) ? json_decode($json, true) : null;
        if (! is_array($m)) {
            $this->error("{$file}: not valid JSON");

            return false;
        }

        $meta = (array) ($m['meta'] ?? []);
        $key = (string) ($meta['key'] ?? '');
        $version = (int) ($meta['version'] ?? 0);
        $assetsBase = trim((string) ($meta['assets_base'] ?? ''), '/');
        if (! preg_match('/^[a-z0-9][a-z0-9-]{1,63}$/', $key) || $version < 1 || ! str_starts_with($assetsBase, 'assets/locked-templates/')) {
            $this->error("{$file}: meta.key / meta.version / meta.assets_base missing or invalid");

            return false;
        }

        $library = null;
        $libFile = dirname($file).'/lib-'.basename($file);
        if (is_file($libFile)) {
            $lib = json_decode((string) file_get_contents($libFile), true);
            if (! is_array($lib) || ! array_is_list($lib)) {
                $this->error("{$libFile}: not a JSON list of sections");

                return false;
            }
            $library = $lib;
        }

        $errors = Manifest::validate($library ? $m + ['library' => $library] : $m);
        if ($errors) {
            $this->error("{$file}: manifest failed validation:");
            foreach ($errors as $e) {
                $this->line("  - {$e}");
            }

            return false;
        }

        $template = LockedTemplate::query()->firstOrNew(['key' => $key]);
        $template->fill([
            'name' => (string) ($meta['name'] ?? $m['name'] ?? $key),
            'description' => (string) ($meta['description'] ?? '') ?: null,
            'category' => (string) ($meta['category'] ?? '') ?: null,
        ]);
        $thumb = trim((string) ($meta['thumbnail'] ?? ''), '/');
        if ($thumb !== '' && str_starts_with($thumb, 'assets/locked-templates/') && ! str_contains($thumb, '..')) {
            $template->thumbnail = $thumb;
        }
        if (! $template->exists) {
            $template->status = 'draft';
        }
        $template->save();

        $existing = LockedTemplateVersion::query()
            ->where('locked_template_id', $template->id)
            ->where('version', $version)
            ->first();

        if ($existing && ! $this->option('force')) {
            $this->line("{$key} v{$version}: already imported (use --force to overwrite)");
        } else {
            LockedTemplateVersion::query()->updateOrCreate(
                ['locked_template_id' => $template->id, 'version' => $version],
                [
                    'manifest' => (string) json_encode($m, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'library' => $library ? (string) json_encode($library, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                    'assets_base' => $assetsBase,
                    'notes' => 'Imported from '.basename(dirname($file)).'/'.basename($file),
                ]
            );
            $this->info("{$key} v{$version}: ".($existing ? 'overwritten' : 'imported').' ('.count(Manifest::slotIndex($m)).' slots'.($library ? ', '.count($library).' library sections' : '').')');
        }

        if ($this->option('publish')) {
            $template->status = 'published';
            $template->current_version = max((int) $template->current_version, $version);
            $template->save();
            $this->info("{$key}: published, current v{$template->current_version}");
        }

        return true;
    }
}
