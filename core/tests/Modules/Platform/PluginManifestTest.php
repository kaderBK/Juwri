<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Juwri\Core\Support\PluginZipValidator;
use Tests\TestCase;
use ZipArchive;

/**
 * B2 (P0-5 follow-up): plugin manifests are PARSED, not executed — plugin.json
 * preferred, plugin.php legacy fallback; the validator accepts json-only zips.
 *
 * The scratch plugin folder is named per PROCESS (`manifesttest_<pid>`): the
 * test suite may run in parallel workers, and the shared `plugins/manifesttest`
 * folder made two workers overwrite (and delete) each other's manifest.
 */
class PluginManifestTest extends TestCase
{
    private string $plugin;

    private string $dir;

    private string $scratch;

    private string $zipPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->plugin = 'manifesttest_'.getmypid();
        $this->dir = base_path('plugins/'.$this->plugin);
        $this->scratch = storage_path('framework/testing/plugin-manifest-'.getmypid());
        $this->zipPath = storage_path('app/manifest-test-plugin.zip');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        File::deleteDirectory($this->scratch);
        @unlink($this->zipPath);
        parent::tearDown();
    }

    public function test_json_manifest_is_parsed_and_wins_over_php(): void
    {
        File::ensureDirectoryExists($this->dir);
        File::put($this->dir.'/plugin.json', json_encode(['id' => $this->plugin, 'name' => 'From JSON', 'version' => '1.0']));
        // A php manifest that would PROVE execution by writing a marker file.
        File::put($this->dir.'/plugin.php', '<?php file_put_contents(__DIR__."/EXECUTED", "1"); return ["id" => "'.$this->plugin.'", "name" => "From PHP"];');

        $manifest = plugin_manifest($this->dir);

        $this->assertSame('From JSON', $manifest['name'], 'json preferred');
        $this->assertFileDoesNotExist($this->dir.'/EXECUTED', 'plugin.php was NEVER executed');

        $plugin = get_plugin($this->plugin);
        $this->assertSame('From JSON', $plugin->name);
        $this->assertFalse($plugin->enabled);
    }

    public function test_legacy_php_manifest_still_loads_when_no_json(): void
    {
        File::ensureDirectoryExists($this->dir);
        File::put($this->dir.'/plugin.php', '<?php return ["id" => "'.$this->plugin.'", "name" => "Legacy"];');

        $this->assertSame('Legacy', plugin_manifest($this->dir)['name']);
        $this->assertSame('Legacy', get_plugin($this->plugin)->name);
    }

    /**
     * A broken (or vanishing) legacy manifest skips THAT plugin — it must never
     * take the app down, because get_installed_plugins() globs plugins/ on
     * every boot.
     */
    public function test_a_broken_legacy_manifest_is_skipped_not_fatal(): void
    {
        Exceptions::fake();

        File::ensureDirectoryExists($this->scratch);
        File::put($this->scratch.'/plugin.php', '<?php throw new \RuntimeException("broken legacy manifest");');

        $this->assertNull(plugin_manifest($this->scratch), 'a throwing plugin.php is skipped, not fatal');
        Exceptions::assertReported(\RuntimeException::class);
    }

    public function test_all_in_repo_plugins_ship_a_json_manifest_matching_their_php(): void
    {
        $dirs = glob(base_path('plugins/*'), GLOB_ONLYDIR);
        $this->assertNotEmpty($dirs);

        foreach ($dirs as $dir) {
            // Scratch folders of this (or another parallel worker's) test run —
            // they are not real plugins and are gone again after the run.
            if (str_starts_with(basename($dir), 'manifesttest')) {
                continue;
            }
            if (! is_file($dir.'/plugin.php')) {
                continue;
            }
            $this->assertFileExists($dir.'/plugin.json', basename($dir).' must ship plugin.json');
            $json = json_decode(File::get($dir.'/plugin.json'), true);
            $php = require $dir.'/plugin.php';
            $this->assertSame($php['id'], $json['id'], basename($dir).' manifests agree');
        }
    }

    public function test_validator_accepts_a_json_only_archive_and_rejects_manifestless(): void
    {
        $zip = new ZipArchive;
        $zip->open($this->zipPath, ZipArchive::CREATE);
        $zip->addFromString('plugin.json', json_encode(['id' => 'jsononly', 'name' => 'J', 'version' => '1']));
        $zip->addFromString('assets/style.css', '.x{}');
        $zip->close();

        PluginZipValidator::assertSafe($this->zipPath); // no exception

        @unlink($this->zipPath);
        $zip = new ZipArchive;
        $zip->open($this->zipPath, ZipArchive::CREATE);
        $zip->addFromString('readme.txt', 'no manifest here');
        $zip->close();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('missing plugin.json');
        PluginZipValidator::assertSafe($this->zipPath);
    }
}
