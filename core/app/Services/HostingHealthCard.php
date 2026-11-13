<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services;

use App\Support\HostingPreflight;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * P6-138: the admin dashboard "Hosting health" card. The checks live in {@see HostingPreflight::healthWarnings()}
 * (one set of probes shared with `juwri:preflight`); this class caches the raw result for five minutes so the
 * dashboard never waits on DNS, HTTP or table probes, and turns each warning code into a plain-language
 * title, a fix line and a link to the matching deploy/HOSTING.md section. Translation happens at render time,
 * so the cache holds codes only and every admin sees their own language.
 */
class HostingHealthCard
{
    public const CACHE_KEY = 'hosting_health_card';

    public const TTL_SECONDS = 300;

    /** HOSTING.md section anchor per warning code. */
    public const ANCHORS = [
        'profile_unset' => 'hosting-profile',
        'php_old' => 'php-version',
        'scheduler_stale' => 'cron-and-scheduler',
        'web_cron_only' => 'cron-and-scheduler',
        'queue_backlog' => 'queue-workers',
        'failed_jobs' => 'queue-workers',
        'core_exposed' => 'document-root',
        'driver_table_missing' => 'cache-session-queue-drivers',
        'mail_spf' => 'mail-dns',
        'mail_dmarc' => 'mail-dns',
        'web_exposure' => 'document-root',
    ];

    public function __construct(private readonly HostingPreflight $preflight) {}

    /**
     * @return array{warnings: list<array{code: string, title: string, fix: string, url: string}>, skipped: list<string>}
     */
    public function get(): array
    {
        $raw = $this->raw();

        return [
            'warnings' => array_map(fn (array $w) => $this->present($w['code'], $w['params']), $raw['warnings']),
            'skipped' => array_map(fn (string $code) => $this->skippedNote($code), $raw['skipped']),
        ];
    }

    public function forget(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable) {
            // nothing cached when the cache store itself is broken
        }
    }

    /** @return array{warnings: list<array{code: string, params: array<string, scalar>}>, skipped: list<string>} */
    private function raw(): array
    {
        $compute = fn (): array => $this->preflight->healthWarnings((bool) config('hosting.profile_explicit'));
        try {
            return Cache::remember(self::CACHE_KEY, self::TTL_SECONDS, $compute);
        } catch (Throwable) {
            return $compute(); // a broken cache store (e.g. its table is missing) is itself one of the warnings
        }
    }

    public function url(string $code): string
    {
        return rtrim((string) config('hosting.docs_url'), '#').'#'.(self::ANCHORS[$code] ?? 'hosting-profile');
    }

    /**
     * @param  array<string, scalar>  $p
     * @return array{code: string, title: string, fix: string, url: string}
     */
    private function present(string $code, array $p): array
    {
        [$title, $fix] = match ($code) {
            'profile_unset' => [
                ___('HOSTING_PROFILE is not set'),
                ___('Add HOSTING_PROFILE=shared, cpanel-vps or docker to your .env file so the right settings are used for your hosting.'),
            ],
            'php_old' => [
                ___('The website runs PHP :version, but PHP 8.4 is needed', ['version' => $p['version'] ?? '']),
                ___('Switch the site to PHP 8.4 in cPanel under Select PHP Version or MultiPHP Manager.'),
            ],
            'scheduler_stale' => [
                ___('The scheduler has not run in the last 5 minutes'),
                ___('Add a cron job that runs every minute: php artisan schedule:run'),
            ],
            'web_cron_only' => [
                ___('Some background tasks (module jobs, large backups) are not running'),
                ___('Only the web cron runs. Add this line in cPanel > Cron Jobs, every minute: :line', ['line' => $p['line'] ?? '']),
            ],
            'queue_backlog' => [
                ___('The oldest waiting job is :minutes minutes old', ['minutes' => $p['minutes'] ?? 0]),
                ___('Start the queue worker, or check that the cron job drains the queue.'),
            ],
            'failed_jobs' => [
                ___(':count jobs have failed', ['count' => $p['count'] ?? 0]),
                ___('Open the failed jobs list, fix the cause, then retry or delete them.'),
            ],
            'core_exposed' => [
                ___('Private files can be opened from the internet: :paths', ['paths' => $p['paths'] ?? '']),
                ___('Point your domain at the public folder or add the deny rules from the hosting guide. If .env was exposed, change APP_KEY and your passwords.'),
            ],
            'driver_table_missing' => [
                ___('The :driver driver needs the database table :table, which does not exist', ['driver' => $p['driver'] ?? '', 'table' => $p['table'] ?? '']),
                ___('Run php artisan migrate, or switch that driver to file in your .env file.'),
            ],
            'mail_spf' => [
                ___('No SPF record found for :domain', ['domain' => $p['domain'] ?? '']),
                ___('Add a TXT record starting with v=spf1 for your domain at your DNS host, so your emails are not marked as spam.'),
            ],
            'mail_dmarc' => [
                ___('No DMARC record found for :domain', ['domain' => $p['domain'] ?? '']),
                ___('Add a TXT record named _dmarc with the value v=DMARC1; p=none at your DNS host.'),
            ],
            default => [$code, ''],
        };

        return ['code' => $code, 'title' => $title, 'fix' => $fix, 'url' => $this->url($code)];
    }

    private function skippedNote(string $code): string
    {
        return match ($code) {
            'web_exposure' => ___('Web exposure check skipped: this server cannot send a request to its own address.'),
            'mail_dns' => ___('Mail DNS check skipped: DNS lookups are not available here.'),
            default => $code,
        };
    }
}
