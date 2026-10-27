<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Reservations\Console\Commands;

use Juwri\Modules\Reservations\Services\Rwg\Feeds\RwgFeedBuilder;
use Juwri\Modules\Reservations\Services\Rwg\Feeds\RwgFeedValidator;
use Juwri\Modules\Reservations\Services\Rwg\RwgFeedDrop;
use Juwri\Modules\Reservations\Services\Rwg\Sftp\PhpseclibSftpTransport;
use Juwri\Modules\Reservations\Services\Rwg\Sftp\RwgSftpTransport;
use Juwri\Modules\Reservations\Services\Rwg\RwgService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * GOOGLE-PARTNER Lane C — build + drop the Reserve-with-Google feeds.
 *
 * Builds every registered RwgFeedContributor (Merchants / Services / Availability,
 * plus Lane D's Waitlist), writes a plain + gzipped artifact to storage/app/rwg/ for
 * inspection, then — only when the integration is `real` AND SFTP is configured —
 * uploads the gzipped files to the Actions Center SFTP drop via phpseclib.
 *
 * DARK by default: `enabled` off ⇒ the command no-ops (nightly dark no-op). Enabled
 * but SFTP unconfigured ⇒ artifacts are written locally and the upload is skipped.
 * No real endpoint is ever contacted in tests or a dark build.
 */
class RwgFeeds extends Command
{
    protected $signature = 'rwg:feeds {--no-upload : Build + write artifacts but never upload}';

    protected $description = 'Build Reserve-with-Google feeds and drop them via SFTP (dark when unconfigured)';

    public function handle(RwgFeedBuilder $builder): int
    {
        if (! RwgService::enabled()) {
            $this->info('[rwg] disabled — feeds skipped (dark).');

            return self::SUCCESS;
        }

        $feeds = $builder->buildAll();
        if ($feeds === []) {
            $this->info('[rwg] no eligible merchants — nothing to write.');

            return self::SUCCESS;
        }

        // Validate in dependency order (merchants, services, availability), drop what breaks a rule, sort, shard.
        $order = ['merchant_feed.json' => 0, 'service_feed.json' => 1, 'availability_feed.json' => 2];
        uksort($feeds, fn ($a, $b) => ($order[$a] ?? 9) <=> ($order[$b] ?? 9));
        $validator = new RwgFeedValidator(allowHttp: app()->environment(['local', 'testing']));
        $problems = [];
        $files = [];
        $size = (int) config('services.reserve_with_google.feed_shard_size', 20000);
        foreach ($feeds as $filename => $payload) {
            $clean = $validator->sanitize($filename, $payload);
            foreach ($clean['problems'] as $p) {
                $problems[] = $p;
                $this->warn("[rwg] {$filename}: {$p}");
            }
            foreach (RwgFeedBuilder::shard($filename, RwgFeedBuilder::sorted($filename, $clean['payload']), $size) as $name => $shard) {
                $files[$name] = $shard;
            }
        }

        $dir = RwgFeedBuilder::feedDir();
        File::ensureDirectoryExists($dir);
        foreach (glob($dir.'/*.json*') ?: [] as $old) {
            File::delete($old); // stale shards of an earlier run must not stay next to the new ones
        }

        $gzFiles = [];
        foreach ($files as $filename => $payload) {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                Log::warning('[rwg] feed encode failed', ['file' => $filename]);
                $problems[] = "{$filename}: could not be encoded";

                continue;
            }
            $plainPath = $dir.'/'.$filename;
            $gzPath = $plainPath.'.gz';
            File::put($plainPath, $json);
            File::put($gzPath, (string) gzencode($json, 9));
            $gzFiles[] = $gzPath;
            $this->info("[rwg] wrote {$filename} (".strlen($json).' bytes)');
        }

        $transport = app()->bound(RwgSftpTransport::class) ? app(RwgSftpTransport::class) : new PhpseclibSftpTransport();
        $drop = new RwgFeedDrop($transport);
        if ($this->option('no-upload') || ! RwgService::isReal() || ! RwgService::sftpConfigured()) {
            $drop->recordWrittenOnly($gzFiles, $problems, 'not uploaded (no SFTP, fake driver or --no-upload)');
            $this->info('[rwg] SFTP not configured (or --no-upload) — artifacts left in storage/app/rwg for inspection.');

            return self::SUCCESS;
        }

        $status = $drop->send($gzFiles, $problems);
        $this->info('[rwg] drop '.$status['status']);

        return self::SUCCESS;
    }
}
