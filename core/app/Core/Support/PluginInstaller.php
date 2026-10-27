<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Installs an uploaded plugin package into `plugins/<id>`.
 *
 * The archive is checked (traversal, symlinks, size) before anything is
 * extracted, the manifest is read (plugin.json preferred; nothing is executed
 * for a plugin.json package), the id must be a safe folder name and the
 * plugin must support this app version. An existing copy of the same plugin
 * is replaced. Scratch files are always removed. Failures throw a
 * RuntimeException whose message is shown to the admin.
 */
final class PluginInstaller
{
    public function install(UploadedFile $package): object
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException(___('ZipArchive extension is not enabled'));
        }

        $scratch = storage_path('app/temp');
        $name = 'plugin-'.Str::random(20);
        $archive = $scratch.'/'.$name.'.zip';
        $unpacked = $scratch.'/'.$name;

        try {
            File::ensureDirectoryExists($scratch);
            if (! File::copy($package->getRealPath(), $archive)) {
                throw new RuntimeException(___('File upload error.'));
            }

            PluginZipValidator::assertSafe($archive);
            $this->extract($archive, $unpacked);

            $manifest = plugin_manifest($unpacked);
            $plugin = $manifest === null ? null : array_to_object($manifest);
            if (! is_object($plugin) || ! PluginZipValidator::isSafePluginId($plugin->id ?? null)) {
                throw new RuntimeException(___('Invalid plugin zip file.'));
            }

            self::assertCompatible($plugin);

            $target = base_path('plugins/'.$plugin->id);
            File::deleteDirectory($target);
            File::ensureDirectoryExists(dirname($target));
            if (! File::moveDirectory($unpacked, $target)) {
                throw new RuntimeException(___('File upload error.'));
            }

            return $plugin;
        } finally {
            File::delete($archive);
            File::deleteDirectory($unpacked);
        }
    }

    /** Refuse a plugin that needs a newer app version than this one. */
    public static function assertCompatible(object $plugin): void
    {
        $needs = (string) ($plugin->min_app_version ?? '0');
        if (version_compare((string) config('appinfo.version'), $needs, '<')) {
            throw new RuntimeException(___('This plugin is not compatible with the current version of your script. Update your script to version <strong>:VERSION_CODE</strong> or higher.',
                ['VERSION_CODE' => $needs]));
        }
    }

    private function extract(string $archive, string $into): void
    {
        $zip = new ZipArchive;
        if ($zip->open($archive) !== true) {
            throw new RuntimeException(___('Could not open the plugin zip file'));
        }
        $extracted = $zip->extractTo($into);
        $zip->close();

        if (! $extracted) {
            throw new RuntimeException(___('Could not open the plugin zip file'));
        }
    }
}
