<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\LegacyCompat;

use Illuminate\Support\Facades\Storage;
use Juwri\Modules\LegacyCompat\Support\LegacyCompatRoutes;
use Juwri\Modules\LegacyCompat\Support\LegacyMediaIndex;
use Juwri\Modules\LegacyCompat\Support\LegacyMediaManifest;
use Tests\TestCase;

/**
 * M6: a migrated install redirects old public media URLs (301) to the target disk, using only the media manifest.
 * Synthetic fixtures and a fake disk only.
 */
class LegacyMediaRedirectTest extends TestCase
{
    private const URL = 'https://media.example.test';

    private string $directory;

    private string $manifestPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/juwri-legacy-media-redirect-'.bin2hex(random_bytes(8));
        $this->manifestPath = $this->directory.'/manifest.json';

        // Storage::fake() builds the disk config at call time; pass the url it must use for url().
        Storage::fake('legacy_dst', ['url' => self::URL]);
        config([
            'filesystems.disks.legacy_dst.url' => self::URL,
            'legacy_compat.enabled' => true,
            'legacy_compat.media.to_disk' => 'legacy_dst',
            'legacy_compat.media.manifest' => $this->manifestPath,
        ]);
        LegacyCompatRoutes::register();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);

        parent::tearDown();
    }

    public function test_a_changed_manifest_without_a_rebuild_is_a_404(): void
    {
        $this->writeManifest([self::entry('restaurant/logo/logo-1.png', 'stores/7/9/logo/logo-1.png')]);
        $this->get('/storage/restaurant/logo/logo-1.png')->assertStatus(301);

        $changed = new LegacyMediaManifest;
        $changed->put(self::entry('restaurant/logo/logo-1.png', 'stores/7/9/logo/logo-1.png'));
        $changed->put(self::entry('restaurant/logo/logo-2.png', 'stores/7/9/logo/logo-2.png'));
        $changed->write($this->manifestPath);

        $this->get('/storage/restaurant/logo/logo-1.png')->assertNotFound();

        LegacyMediaIndex::build($changed, $this->manifestPath);
        $this->get('/storage/restaurant/logo/logo-2.png')->assertStatus(301);
    }

    public function test_hits_and_misses_never_parse_the_manifest(): void
    {
        $this->writeManifest([self::entry('restaurant/logo/logo-1.png', 'stores/7/9/logo/logo-1.png')]);

        $mtime = (int) filemtime($this->manifestPath);
        $size = (int) filesize($this->manifestPath);
        file_put_contents($this->manifestPath, str_repeat('x', $size));
        touch($this->manifestPath, $mtime);
        clearstatcache();

        $this->get('/storage/restaurant/logo/logo-1.png')->assertStatus(301);
        $this->get('/storage/restaurant/logo/never-seen.png')->assertNotFound();
    }

    public function test_module_off_is_a_404_even_with_a_built_index(): void
    {
        $this->writeManifest([self::entry('restaurant/logo/logo-1.png', 'stores/7/9/logo/logo-1.png')]);
        config(['legacy_compat.enabled' => false]);

        $this->get('/storage/restaurant/logo/logo-1.png')->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function entry(string $source, ?string $target, array $overrides = []): array
    {
        return array_merge([
            'source' => $source,
            'target' => $target,
            'size' => 42,
            'sha256' => str_repeat('a', 64),
            'account_id' => 7,
            'store_id' => 9,
            'user_id' => null,
            'status' => 'copied',
            'note' => null,
        ], $overrides);
    }

    /** @param list<array<string, mixed>> $entries */
    private function writeManifest(array $entries): void
    {
        $manifest = new LegacyMediaManifest;
        foreach ($entries as $entry) {
            $manifest->put($entry);
        }
        $manifest->write($this->manifestPath);
        LegacyMediaIndex::build($manifest, $this->manifestPath);
    }

    public function test_a_migrated_logo_301s_to_the_target_disk_url_without_the_query_string(): void
    {
        $this->writeManifest([
            self::entry('restaurant/logo/logo-1.png', 'stores/7/9/logo/logo-1.png'),
        ]);

        $response = $this->get('/storage/restaurant/logo/logo-1.png?utm_source=print&token=secret');

        $response->assertStatus(301);
        $response->assertRedirect(self::URL.'/stores/7/9/logo/logo-1.png');
        $this->assertSame(self::URL.'/stores/7/9/logo/logo-1.png', $response->headers->get('Location'));
        $cache = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('public', $cache);
        $this->assertStringContainsString('max-age=86400', $cache);
    }

    public function test_the_lowest_store_id_wins_when_two_stores_share_a_source_name(): void
    {
        $this->writeManifest([
            self::entry('restaurant/logo/logo-1.png', 'stores/9/9/logo/logo-1.png', ['store_id' => 9]),
            self::entry('restaurant/logo/logo-1.png', 'stores/3/4/logo/logo-1.png', ['store_id' => 3, 'status' => 'already_present']),
        ]);

        $this->get('/storage/restaurant/logo/logo-1.png')
            ->assertStatus(301)
            ->assertRedirect(self::URL.'/stores/3/4/logo/logo-1.png');
    }

    public function test_conflict_and_missing_entries_do_not_redirect(): void
    {
        $this->writeManifest([
            self::entry('restaurant/logo/logo-1.png', 'stores/1/1/logo/logo-1.png', ['store_id' => 1, 'status' => 'conflict']),
            self::entry('restaurant/logo/logo-1.png', 'stores/2/2/logo/logo-1.png', ['store_id' => 2, 'status' => 'missing']),
        ]);

        $this->get('/storage/restaurant/logo/logo-1.png')->assertNotFound();
    }

    public function test_an_unknown_file_is_a_404(): void
    {
        $this->writeManifest([
            self::entry('restaurant/logo/other.png', 'stores/7/9/logo/other.png'),
        ]);

        $this->get('/storage/restaurant/logo/logo-1.png')->assertNotFound();
    }

    public function test_a_parent_directory_path_is_a_404(): void
    {
        $this->writeManifest([
            self::entry('restaurant/logo/logo-1.png', 'stores/7/9/logo/logo-1.png'),
        ]);

        $this->get('/storage/menu/%2e%2e/secret.png')->assertNotFound();
    }

    public function test_module_off_is_a_404_even_for_a_path_in_the_manifest(): void
    {
        $this->writeManifest([
            self::entry('restaurant/logo/logo-1.png', 'stores/7/9/logo/logo-1.png'),
        ]);
        config(['legacy_compat.enabled' => false]);

        $this->get('/storage/restaurant/logo/logo-1.png')->assertNotFound();
    }

    public function test_a_missing_manifest_is_a_404(): void
    {
        config(['legacy_compat.media.manifest' => $this->directory.'/missing.json']);

        $this->get('/storage/restaurant/logo/logo-1.png')->assertNotFound();
    }

    public function test_an_unreadable_manifest_is_a_404_not_a_500(): void
    {
        mkdir($this->directory, 0777, true);
        file_put_contents($this->manifestPath, '{not json');

        $this->get('/storage/restaurant/logo/logo-1.png')->assertNotFound();
    }

    public function test_an_unconfigured_target_disk_is_a_404(): void
    {
        $this->writeManifest([
            self::entry('restaurant/logo/logo-1.png', 'stores/7/9/logo/logo-1.png'),
        ]);
        config(['legacy_compat.media.to_disk' => 'not_configured']);

        $this->get('/storage/restaurant/logo/logo-1.png')->assertNotFound();
    }

    public function test_missing_media_config_is_a_404(): void
    {
        $this->writeManifest([
            self::entry('restaurant/logo/logo-1.png', 'stores/7/9/logo/logo-1.png'),
        ]);
        config(['legacy_compat.media.to_disk' => null]);

        $this->get('/storage/restaurant/logo/logo-1.png')->assertNotFound();
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $directory.'/'.$name;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($directory);
    }
}
