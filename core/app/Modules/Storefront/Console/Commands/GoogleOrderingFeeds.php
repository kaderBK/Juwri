<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Juwri\Modules\Storefront\Services\GoogleOrdering\Sftp\PhpseclibGoogleOrderingTransport;
use Juwri\Modules\Storefront\Services\GoogleOrdering\Sftp\GoogleOrderingSftpTransport;
use Juwri\Modules\Storefront\Services\GoogleOrdering\GoogleOrderingFeedBuilder;
use Juwri\Modules\Storefront\Services\GoogleOrdering\GoogleOrderingFeedValidator;
use Juwri\Modules\Storefront\Services\GoogleOrdering\GoogleOrderingService;

/**
 * P5-20 — build + drop the Google Ordering Redirect feeds.
 *
 * Builds the Merchants / Services / Menu feeds, writes a plain + gzipped artifact to
 * storage/app/google-ordering/ for inspection, then — only when the integration is `real`
 * AND SFTP is configured — uploads the gzipped files to the Actions Center SFTP drop
 * (the same GoogleOrderingSftpTransport seam Reservations' rwg:feeds uses).
 *
 * DARK by default: `enabled` off ⇒ the command no-ops (nightly dark no-op). Enabled but SFTP
 * unconfigured ⇒ artifacts are written locally and the upload is skipped. No real endpoint is
 * ever contacted in tests or a dark build.
 */
class GoogleOrderingFeeds extends Command
{
    protected $signature = 'google:ordering-feeds {--no-upload : Build + write artifacts but never upload}';

    protected $description = 'Build Google Ordering Redirect feeds and drop them via SFTP (dark when unconfigured)';

    public function handle(GoogleOrderingFeedBuilder $builder): int
    {
        if (! GoogleOrderingService::enabled()) {
            $this->info('[google-ordering] disabled — feeds skipped (dark).');

            return self::SUCCESS;
        }

        $feeds = $builder->buildAll();
        if ((array) ($feeds['merchant_feed.json']['merchant'] ?? []) === []) {
            $this->info('[google-ordering] no eligible merchants — nothing to write.');

            return self::SUCCESS;
        }

        // Validate in dependency order (merchants, services, menu), drop what breaks a rule, sort, shard.
        $order = ['merchant_feed.json' => 0, 'service_feed.json' => 1, 'menu_feed.json' => 2];
        uksort($feeds, fn ($a, $b) => ($order[$a] ?? 9) <=> ($order[$b] ?? 9));
        $validator = new GoogleOrderingFeedValidator(allowHttp: app()->environment(['local', 'testing']));
        $problems = [];
        $files = [];
        $size = GoogleOrderingService::feedShardSize();
        foreach ($feeds as $filename => $payload) {
            $clean = $validator->sanitize($filename, $payload);
            foreach ($clean['problems'] as $p) {
                $problems[] = $p;
                $this->warn("[google-ordering] {$filename}: {$p}");
            }
            foreach (GoogleOrderingFeedBuilder::shard($filename, GoogleOrderingFeedBuilder::sorted($filename, $clean['payload']), $size) as $name => $shard) {
                $files[$name] = $shard;
            }
        }

        // A drop is a full snapshot: if validation emptied the merchant feed, publishing it would delist every store.
        $kept = 0;
        foreach ($files as $name => $shard) {
            if (str_starts_with($name, 'merchant_feed')) {
                $kept += count($shard['merchant'] ?? []);
            }
        }
        if ($kept === 0) {
            $this->error('[google-ordering] validation left no merchant — nothing written or uploaded.');

            return self::FAILURE;
        }

        $dir = GoogleOrderingFeedBuilder::feedDir();
        File::ensureDirectoryExists($dir);
        foreach (glob($dir.'/*.json*') ?: [] as $old) {
            File::delete($old); // stale shards of an earlier run must not stay next to the new ones
        }

        $gzFiles = [];
        foreach ($files as $filename => $payload) {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                Log::warning('[google-ordering] feed encode failed', ['file' => $filename]);
                $problems[] = "{$filename}: could not be encoded";

                continue;
            }
            $plainPath = $dir.'/'.$filename;
            $gzPath = $plainPath.'.gz';
            File::put($plainPath, $json);
            File::put($gzPath, (string) gzencode($json, 9));
            $gzFiles[] = $gzPath;
            $this->info("[google-ordering] wrote {$filename} (".strlen($json).' bytes)');
        }

        if ($this->option('no-upload') || ! GoogleOrderingService::isReal() || ! GoogleOrderingService::sftpConfigured()) {
            $this->info('[google-ordering] SFTP not configured (or --no-upload) — artifacts left in storage/app/google-ordering for inspection.');

            return self::SUCCESS;
        }

        $transport = app()->bound(GoogleOrderingSftpTransport::class) ? app(GoogleOrderingSftpTransport::class) : new PhpseclibGoogleOrderingTransport();
        $this->info('[google-ordering] drop '.$this->upload($transport, $gzFiles));

        return self::SUCCESS;
    }

    /**
     * Upload each gzipped feed with retries (connect + per-file), never throwing: a failed day is
     * logged and the next daily run retries. Only reached with driver=real and SFTP configured.
     *
     * @param  list<string>  $gzFiles  local .gz paths
     * @return 'ok'|'partial'|'failed'
     */
    private function upload(GoogleOrderingSftpTransport $transport, array $gzFiles): string
    {
        $cfg = GoogleOrderingService::sftp();
        $attempts = GoogleOrderingService::sftpAttempts();
        $remoteDir = rtrim($cfg['path'], '/');

        $connected = false;
        for ($i = 1; $i <= $attempts; $i++) {
            try {
                $transport->connect($cfg);
                $connected = true;

                break;
            } catch (\Throwable $e) {
                if ($i < $attempts) {
                    sleep($i * 2);
                } else {
                    Log::warning('[google-ordering] SFTP connect failed', ['error' => mb_substr($e->getMessage(), 0, 300)]);
                }
            }
        }
        if (! $connected) {
            return 'failed';
        }

        $failed = 0;
        foreach ($gzFiles as $local) {
            $remote = ($remoteDir === '' ? '' : $remoteDir.'/').basename($local);
            $sent = false;
            for ($i = 1; $i <= $attempts; $i++) {
                try {
                    $transport->put($remote, $local);
                    $sent = true;

                    break;
                } catch (\Throwable $e) {
                    if ($i < $attempts) {
                        sleep($i * 2);
                    } else {
                        Log::warning('[google-ordering] SFTP put failed', ['file' => basename($local), 'error' => mb_substr($e->getMessage(), 0, 300)]);
                    }
                }
            }
            if (! $sent) {
                $failed++;
            }
        }
        $transport->close();

        $count = count($gzFiles);

        return $failed === 0 ? 'ok' : ($failed === $count ? 'failed' : 'partial');
    }
}
