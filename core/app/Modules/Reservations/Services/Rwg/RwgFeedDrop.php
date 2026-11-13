<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Reservations\Services\Rwg;

use Juwri\Core\Services\AlertDispatcher;
use Juwri\Core\Settings\PlatformSettings;
use Juwri\Modules\Reservations\Services\Rwg\Sftp\RwgSftpTransport;

/**
 * The daily feed drop: upload each gzipped feed with retries, then record what happened (platform setting
 * `rwg_last_drop`, shown on the admin Reserve-with-Google page) and alert the platform admins when anything failed.
 * Never throws: a failed day is reported and the next daily run retries. Dark discipline lives in the caller.
 */
final class RwgFeedDrop
{
    public const SETTING = 'rwg_last_drop';

    public const ALERT_KIND = 'rwg_feed_drop';

    /** @var callable(int): void seconds to wait before the next attempt (replaced in tests) */
    private $sleep;

    public function __construct(private readonly RwgSftpTransport $transport, ?callable $sleep = null)
    {
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * @param  list<string>  $gzFiles  local .gz paths
     * @param  list<string>  $problems  validation problems found while building (kept in the status)
     * @return array<string, mixed> the stored status
     */
    public function send(array $gzFiles, array $problems = [], ?int $attempts = null): array
    {
        $attempts = max(1, $attempts ?? (int) config('services.reserve_with_google.sftp_attempts', 3));
        $cfg = RwgService::sftp();
        $remoteDir = rtrim($cfg['path'], '/');
        $files = [];

        if (! $this->connect($cfg, $attempts, $error)) {
            foreach ($gzFiles as $local) {
                $files[] = ['name' => basename($local), 'status' => 'failed', 'attempts' => $attempts, 'error' => $error];
            }

            return $this->finish('failed', $files, $problems, $error);
        }

        foreach ($gzFiles as $local) {
            $remote = ($remoteDir === '' ? '' : $remoteDir.'/').basename($local);
            $files[] = $this->putWithRetry($remote, $local, $attempts);
        }
        $this->transport->close();

        $failed = count(array_filter($files, fn ($f) => $f['status'] === 'failed'));
        $status = $failed === 0 ? 'ok' : ($failed === count($files) ? 'failed' : 'partial');

        return $this->finish($status, $files, $problems, null);
    }

    /**
     * Record a run that wrote the files but did not upload (no SFTP, or --no-upload).
     *
     * @param  list<string>  $gzFiles
     * @param  list<string>  $problems
     * @return array<string, mixed>
     */
    public function recordWrittenOnly(array $gzFiles, array $problems, string $reason): array
    {
        $files = array_map(fn ($p) => ['name' => basename($p), 'status' => 'written', 'attempts' => 0, 'error' => null], $gzFiles);

        return $this->finish('written', $files, $problems, $reason, alert: false);
    }

    /** @param  array{host:string,port:int,user:string,key:string,path:string}  $cfg */
    private function connect(array $cfg, int $attempts, ?string &$error): bool
    {
        for ($i = 1; $i <= $attempts; $i++) {
            try {
                $this->transport->connect($cfg);

                return true;
            } catch (\Throwable $e) {
                $error = mb_substr($e->getMessage(), 0, 300);
                if ($i < $attempts) {
                    ($this->sleep)($i * 2);
                }
            }
        }

        return false;
    }

    /** @return array{name:string,status:string,attempts:int,error:?string} */
    private function putWithRetry(string $remote, string $local, int $attempts): array
    {
        $error = null;
        for ($i = 1; $i <= $attempts; $i++) {
            try {
                $this->transport->put($remote, $local);

                return ['name' => basename($local), 'status' => 'ok', 'attempts' => $i, 'error' => null];
            } catch (\Throwable $e) {
                $error = mb_substr($e->getMessage(), 0, 300);
                if ($i < $attempts) {
                    ($this->sleep)($i * 2);
                }
            }
        }

        return ['name' => basename($local), 'status' => 'failed', 'attempts' => $attempts, 'error' => $error];
    }

    /**
     * @param  list<array{name:string,status:string,attempts:int,error:?string}>  $files
     * @param  list<string>  $problems
     * @return array<string, mixed>
     */
    private function finish(string $status, array $files, array $problems, ?string $error, bool $alert = true): array
    {
        $record = [
            'at' => now()->toIso8601String(),
            'status' => $status,
            'error' => $error,
            'files' => $files,
            'problem_count' => count($problems),
            'problems' => array_slice($problems, 0, 50),
        ];
        try {
            PlatformSettings::set(self::SETTING, json_encode($record, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable) {
            // status is a convenience; never fail the drop because it could not be saved
        }
        if ($alert && in_array($status, ['failed', 'partial'], true)) {
            AlertDispatcher::send(self::ALERT_KIND, "Reserve with Google feed drop {$status}: ".($error ?? implode(', ', array_map(fn ($f) => $f['name'], array_filter($files, fn ($f) => $f['status'] === 'failed')))));
        }

        return $record;
    }

    /** @return array<string, mixed>|null */
    public static function last(): ?array
    {
        $raw = PlatformSettings::get(self::SETTING);
        $data = is_string($raw) ? json_decode($raw, true) : (is_array($raw) ? $raw : null);

        return is_array($data) ? $data : null;
    }
}
