<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 | Plugin registry helpers. A plugin is a folder under core/plugins/<id>/ with a
 | manifest; it is "enabled" when its id is in settings.active_plugins.
 */

/**
 * B2 (P0-5 follow-up): read a plugin manifest, PARSE-DON'T-EXECUTE.
 * plugin.json (decoded, never executed) is preferred; plugin.php (require —
 * runs code) remains as the legacy fallback so old zips keep installing.
 * All in-repo plugins ship plugin.json, so nothing executes for them.
 *
 * @return array|null manifest data, or null when neither file exists/parses
 */
function plugin_manifest(string $dir): ?array
{
    $json = $dir.'/plugin.json';
    if (file_exists($json)) {
        $data = json_decode((string) file_get_contents($json), true);
        if (is_array($data) && ! empty($data)) {
            return $data;
        }
    }

    $php = $dir.'/plugin.php';
    if (file_exists($php)) {
        try {
            $data = require $php; // legacy path — executed by design, see P0-5
        } catch (Throwable $e) {
            /* A broken (or vanishing) legacy manifest must skip that ONE plugin,
               never take the whole app down: get_installed_plugins() globs
               plugins/ on every boot, so a single bad plugin.php would break
               every request. */
            report($e);

            return null;
        }

        return is_array($data) ? $data : null;
    }

    return null;
}

/** Folder of plugin `$id` under core/plugins. */
function plugin_dir(string $id): string
{
    return base_path('plugins/'.$id);
}

/**
 * Every plugin folder that has a readable manifest, keyed by folder name
 * (see get_plugin() for the value).
 *
 * @return array<string, object>
 */
function get_installed_plugins(): array
{
    $found = [];
    foreach (glob(plugin_dir('*'), GLOB_ONLYDIR) ?: [] as $folder) {
        $id = basename($folder);
        $plugin = get_plugin($id);
        if ($plugin !== false) {
            $found[$id] = $plugin;
        }
    }

    return $found;
}

/**
 * The manifest of plugin `$id` as an object (nested data too) plus an
 * `enabled` flag, or false when the folder has no manifest.
 */
function get_plugin(mixed $id): object|false
{
    $manifest = plugin_manifest(plugin_dir((string) $id));
    if ($manifest === null) {
        return false;
    }

    $plugin = array_to_object($manifest);
    $plugin->enabled = is_plugin_enabled($plugin->id);

    return $plugin;
}

/** Whether plugin `$id` is installed (has a manifest file) and listed in settings.active_plugins. */
function is_plugin_enabled(mixed $id): bool
{
    $folder = plugin_dir((string) $id);
    $installed = file_exists($folder.'/plugin.json') || file_exists($folder.'/plugin.php');

    return $installed && in_array($id, (array) config('settings.active_plugins', []));
}

/** Public URL of a file in an enabled plugin's assets folder; 404 while the plugin is disabled. */
function plugin_assets(mixed $id, mixed $file): string
{
    abort_unless(is_plugin_enabled($id), 404);

    return asset("core/plugins/{$id}/assets/{$file}");
}
