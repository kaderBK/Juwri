<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\GoogleOrdering;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Juwri\Modules\Storefront\Services\GoogleOrdering\Sftp\GoogleOrderingSftpTransport;
use Tests\TestCase;

/**
 * P5-20 Google Ordering Redirect — the `google:ordering-feeds` command.
 *
 * Dark no-op when disabled; with `driver=fake` it writes the plain + gzipped artifacts locally and
 * NEVER touches SFTP or HTTP; `--no-upload` writes without uploading; stale shards are swept.
 */
class GoogleOrderingCommandTest extends TestCase
{
    use RefreshDatabase;
    use GoogleOrderingFixtures;

    private const FILES = ['merchant_feed.json', 'service_feed.json', 'menu_feed.json'];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['settings.include_language_code' => false]);
        $this->makeFeedDir();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->goDir);
        parent::tearDown();
    }

    private function enabledStore(): void
    {
        $store = $this->foodStore('cmd-'.Str::lower(Str::random(6)), 'Cmd');
        $this->googleOn($store);
        $this->geocodedBranch($store);
    }

    public function test_dark_noop_writes_nothing_when_disabled(): void
    {
        config(['services.google_ordering.enabled' => false]);

        $this->artisan('google:ordering-feeds')->assertExitCode(0);

        foreach (self::FILES as $file) {
            $this->assertFileDoesNotExist($this->goDir.'/'.$file);
        }
    }

    public function test_enabled_fake_writes_plain_and_gz_and_never_uploads(): void
    {
        $fake = new FakeGoogleOrderingTransport;
        $this->app->instance(GoogleOrderingSftpTransport::class, $fake);
        Http::fake();
        config(['services.google_ordering.enabled' => true, 'services.google_ordering.driver' => 'fake']);
        $this->enabledStore();

        $this->artisan('google:ordering-feeds')->assertExitCode(0);

        foreach (self::FILES as $file) {
            $this->assertFileExists($this->goDir.'/'.$file, $file.' written');
            $this->assertFileExists($this->goDir.'/'.$file.'.gz', $file.'.gz written');
        }

        $decoded = $this->readFeed('merchant_feed.json');
        $this->assertNotSame([], $this->entriesOf($decoded));

        $this->assertSame(0, $fake->puts, 'no SFTP put in fake mode');
        $this->assertSame(0, $fake->connects, 'no SFTP connection in fake mode');
        Http::assertNothingSent();
    }

    public function test_no_upload_writes_but_does_not_upload(): void
    {
        $fake = new FakeGoogleOrderingTransport;
        $this->app->instance(GoogleOrderingSftpTransport::class, $fake);
        Http::fake();
        config(['services.google_ordering.enabled' => true, 'services.google_ordering.driver' => 'fake']);
        $this->enabledStore();

        $this->artisan('google:ordering-feeds', ['--no-upload' => true])->assertExitCode(0);

        foreach (self::FILES as $file) {
            $this->assertFileExists($this->goDir.'/'.$file);
            $this->assertFileExists($this->goDir.'/'.$file.'.gz');
        }
        $this->assertSame(0, $fake->puts);
        Http::assertNothingSent();
    }

    public function test_stale_shards_are_removed(): void
    {
        File::ensureDirectoryExists($this->goDir);
        File::put($this->goDir.'/merchant_feed.shard-9-of-9.json', '{}');
        File::put($this->goDir.'/menu_feed.shard-2-of-2.json.gz', 'stale');

        config(['services.google_ordering.enabled' => true, 'services.google_ordering.driver' => 'fake']);
        $this->enabledStore();

        $this->artisan('google:ordering-feeds')->assertExitCode(0);

        $this->assertFileDoesNotExist($this->goDir.'/merchant_feed.shard-9-of-9.json');
        $this->assertFileDoesNotExist($this->goDir.'/menu_feed.shard-2-of-2.json.gz');
        $this->assertFileExists($this->goDir.'/merchant_feed.json');
    }
}
