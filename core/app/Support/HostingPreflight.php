<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Services\Ops\DatabaseDump;
use Juwri\Core\Support\UploadLimit;
use Throwable;

/**
 * P6-123 (absorbs P6-119b): host requirement checks behind `juwri:preflight`. Every probe of the host
 * (PHP version, disabled functions, extensions, daemons) is a protected method so tests can fake it.
 */
class HostingPreflight
{
    public const MIN_PHP = '8.4.0';

    /**
     * SHARED-HOSTING.md §1 (composer.lock + app code). intl/gmp/bcmath/pcntl/redis are not required.
     */
    public const REQUIRED_EXTENSIONS = [
        'sodium', 'openssl', 'mbstring', 'iconv', 'curl', 'gd', 'zip', 'dom', 'libxml', 'simplexml',
        'fileinfo', 'json', 'ctype', 'filter', 'tokenizer', 'session', 'zlib', 'pdo_mysql',
    ];

    /** P6-138: a scheduler heartbeat older than this (seconds) is stale; same limit as the cron check. */
    public const SCHEDULER_STALE = 300;

    /** P6-138: the oldest waiting job being older than this (seconds) means the queue is backing up. */
    public const QUEUE_BACKLOG_STALE = 600;

    /**
     * P6-212: small per-file ceiling the app assumes. Below this, the host's
     * `upload_max_filesize` / `post_max_size` will reject every backup, image
     * import or larger media file with a 413 the owner cannot diagnose.
     */
    public const MIN_UPLOAD_MB = 64;

    public const OK = 'ok';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    /**
     * @return list<array{check: string, status: string, detail: string}>
     */
    public function run(string $profile, ?string $webPhpVersion): array
    {
        $rows = [];
        $add = function (string $check, string $status, string $detail) use (&$rows): void {
            $rows[] = ['check' => $check, 'status' => $status, 'detail' => $detail];
        };

        $cli = $this->phpVersion();
        $add('PHP (CLI)', $this->phpOk($cli) ? self::OK : self::FAIL, $this->phpOk($cli) ? $cli : $this->phpMessage($cli));
        if ($webPhpVersion === null) {
            $add('PHP (web)', self::WARN, 'Not checked — see the curl line below, or re-run with the site reachable at APP_URL');
        } else {
            $add('PHP (web)', $this->phpOk($webPhpVersion) ? self::OK : self::FAIL, $this->phpOk($webPhpVersion) ? $webPhpVersion : $this->phpMessage($webPhpVersion));
        }

        $missing = array_values(array_filter(self::REQUIRED_EXTENSIONS, fn (string $ext) => ! $this->extensionLoaded($ext)));
        $add('PHP extensions', $missing === [] ? self::OK : self::FAIL, $missing === [] ? 'All required extensions loaded' : 'Missing: '.implode(', ', $missing));

        $add(...$this->backupCheck());
        $offbox = (bool) config('filesystems.disks.s3.key') && (bool) config('filesystems.disks.s3.bucket');
        $add('Off-box backup', $offbox ? self::OK : self::WARN, $offbox
            ? 'S3 bucket '.config('filesystems.disks.s3.bucket') // P6-166: the bucket name only, never the keys
            : 'No off-box target: every DB dump stays on this server. Set AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_BUCKET (and AWS_ENDPOINT for Backblaze/Wasabi/MinIO)');

        foreach (['storage' => storage_path(), 'bootstrap/cache' => base_path('bootstrap/cache')] as $label => $path) {
            $ok = $this->writable($path);
            $add("Writable {$label}", $ok ? self::OK : self::FAIL, $ok ? $path : "{$path} is not writable by PHP");
        }

        $add(...$this->uploadCheck());

        $add(...$this->cronCheck($profile));
        if ($this->webCronOnly()) {
            $add('CLI cron', self::WARN, 'Only the web cron (/cronjob) runs the scheduler, without proc_open: module tasks and big backups are skipped. Add the CLI cron line: '.$this->cliCronLine().' (deploy/HOSTING.md#cron-and-scheduler)');
        }
        $add(...$this->workerCheck($profile));
        $add(...$this->redisCheck($profile));
        foreach ($this->databaseDriverChecks() as $row) {
            $add(...$row);
        }
        $add(...$this->storageLinkCheck());
        $add(...$this->mailCheck());
        foreach ($this->mailDomainChecks() as $row) {
            $add(...$row);
        }
        $add(...$this->optionalToolCheck('Studio PDF/image export (Node + Chrome)', $this->headlessBrowserReason(), 'Studio export stays off; users get the browser print fallback'));
        $add(...$this->optionalToolCheck('Directory import (DuckDB)', $this->duckDbReason(), 'Directory import stays off; run it on another machine'));

        return $rows;
    }

    /**
     * @param  list<array{check: string, status: string, detail: string}>  $rows
     */
    public static function hasBlockers(array $rows): bool
    {
        return in_array(self::FAIL, array_column($rows, 'status'), true);
    }

    /** Guess the profile from the host: docker markers, disabled process functions, root/supervisor. */
    public function detectProfile(): string
    {
        if ($this->dockerMarkers()) {
            return 'docker';
        }
        if (! $this->functionAvailable('proc_open') || ! $this->functionAvailable('exec')) {
            return 'shared';
        }
        if ($this->isRoot() || $this->binaryOnPath('supervisorctl') || $this->binaryOnPath('systemctl')) {
            return 'cpanel-vps';
        }

        return 'shared';
    }

    public function phpMessage(string $version): string
    {
        return "PHP 8.4 required; your host offers {$version} — switch in cPanel > Select PHP Version / MultiPHP";
    }

    private function phpOk(string $version): bool
    {
        return version_compare($version, self::MIN_PHP, '>=');
    }

    /**
     * P6-138: the live hosting warnings behind the admin health card. Every condition reuses a probe of this class
     * (so tests fake the host the same way). Returns warnings as `code => params` plus the checks that could not run.
     *
     * @return array{warnings: list<array{code: string, params: array<string, scalar>}>, skipped: list<string>}
     */
    public function healthWarnings(bool $profileExplicit): array
    {
        $warnings = [];
        $skipped = [];
        $warn = function (string $code, array $params = []) use (&$warnings): void {
            $warnings[] = ['code' => $code, 'params' => $params];
        };

        if (! $profileExplicit) {
            $warn('profile_unset');
        }

        $web = $this->phpVersion(); // the card renders inside a web request, so this is the web PHP
        if (! $this->phpOk($web)) {
            $warn('php_old', ['version' => $web]);
        }

        try {
            $age = $this->schedulerAge();
        } catch (Throwable) {
            $age = null; // e.g. a database cache store whose table is missing
        }
        if ($age === null || $age > self::SCHEDULER_STALE) {
            $warn('scheduler_stale');
        } elseif ($this->webCronOnly()) {
            $warn('web_cron_only', ['line' => $this->cliCronLine()]);
        }

        $backlog = $this->queueBacklogAge();
        if ($backlog !== null && $backlog > self::QUEUE_BACKLOG_STALE) {
            $warn('queue_backlog', ['minutes' => intdiv($backlog, 60)]);
        }
        $failed = $this->failedJobsCount();
        if ($failed > 0) {
            $warn('failed_jobs', ['count' => $failed]);
        }

        $exposed = $this->exposedPaths();
        if ($exposed === null) {
            $skipped[] = 'web_exposure';
        } elseif ($exposed !== []) {
            $warn('core_exposed', ['paths' => implode(', ', $exposed)]);
        }

        foreach ($this->missingDriverTables() as $driver => $table) {
            $warn('driver_table_missing', ['driver' => $driver, 'table' => $table]);
        }

        $mail = $this->mailAuthStatus();
        if ($mail === null) {
            $skipped[] = 'mail_dns';
        } else {
            foreach ($mail as $missing) {
                $warn($missing === 'spf' ? 'mail_spf' : 'mail_dmarc', ['domain' => $this->mailDomain()]);
            }
        }

        return ['warnings' => $warnings, 'skipped' => $skipped];
    }

    /** Domain of MAIL_FROM_ADDRESS (empty when unset). */
    protected function mailDomain(): string
    {
        $address = (string) config('mail.from.address');

        return str_contains($address, '@') ? strtolower(substr(strrchr($address, '@'), 1)) : '';
    }

    /**
     * Missing mail DNS records: a list of 'spf' / 'dmarc'. null = not checked (mail sends nothing, no real domain,
     * or DNS lookups unavailable).
     *
     * @return list<string>|null
     */
    protected function mailAuthStatus(): ?array
    {
        $mailer = (string) config('mail.default');
        $transport = (string) config("mail.mailers.{$mailer}.transport", $mailer);
        $domain = $this->mailDomain();
        if (in_array($transport, ['log', 'array'], true) || $domain === '' || str_ends_with($domain, '.test') || str_ends_with($domain, '.localhost') || $domain === 'example.com') {
            return null;
        }
        $spf = $this->dnsTxt($domain);
        $dmarc = $this->dnsTxt('_dmarc.'.$domain);
        if ($spf === null || $dmarc === null) {
            return null;
        }
        $missing = [];
        if (! $this->txtHas($spf, 'v=spf1')) {
            $missing[] = 'spf';
        }
        if (! $this->txtHas($dmarc, 'v=dmarc1')) {
            $missing[] = 'dmarc';
        }

        return $missing;
    }

    /** @param  list<string>  $records */
    private function txtHas(array $records, string $prefix): bool
    {
        foreach ($records as $record) {
            if (str_starts_with(strtolower(trim($record)), $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The database tables behind a `database` session / cache / queue driver that do not exist: driver label => table.
     *
     * @return array<string, string>
     */
    protected function missingDriverTables(): array
    {
        $cacheStore = (string) config('cache.default');
        $queue = (string) config('queue.default');
        $wanted = array_filter([
            'session' => config('session.driver') === 'database' ? (string) config('session.table', 'sessions') : null,
            'cache' => config("cache.stores.{$cacheStore}.driver") === 'database' ? (string) config("cache.stores.{$cacheStore}.table", 'cache') : null,
            'queue' => config("queue.connections.{$queue}.driver") === 'database' ? (string) config("queue.connections.{$queue}.table", 'jobs') : null,
        ]);
        $missing = [];
        foreach ($wanted as $driver => $table) {
            try {
                if (! Schema::hasTable($table)) {
                    $missing[$driver] = $table;
                }
            } catch (Throwable) {
                $missing[$driver] = $table;
            }
        }

        return $missing;
    }

    /** Age in seconds of the oldest waiting job (database queue only); null when none is waiting or the queue is not a database one. */
    protected function queueBacklogAge(): ?int
    {
        $queue = (string) config('queue.default');
        if (config("queue.connections.{$queue}.driver") !== 'database') {
            return null;
        }
        try {
            $table = (string) config("queue.connections.{$queue}.table", 'jobs');
            $oldest = Schema::hasTable($table) ? DB::table($table)->whereNull('reserved_at')->min('created_at') : null;
        } catch (Throwable) {
            return null;
        }

        return $oldest === null ? null : max(0, time() - (int) $oldest);
    }

    protected function failedJobsCount(): int
    {
        try {
            $table = (string) config('queue.failed.table', 'failed_jobs');

            return Schema::hasTable($table) ? (int) DB::table($table)->count() : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Paths under core/ that a visitor can fetch over HTTP (core/.env, core/composer.json). null = the check was
     * skipped (local APP_URL, or this server cannot make an HTTP request to itself).
     *
     * @return list<string>|null
     */
    protected function exposedPaths(): ?array
    {
        $base = rtrim((string) config('app.url'), '/');
        $host = strtolower((string) parse_url($base, PHP_URL_HOST));
        if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true) || str_ends_with($host, '.test') || str_ends_with($host, '.localhost')) {
            return null;
        }
        $exposed = [];
        try {
            foreach (['core/.env' => 'APP_KEY', 'core/composer.json' => 'laravel/framework'] as $path => $needle) {
                $response = Http::connectTimeout(2)->timeout(3)->withoutRedirecting()->get("{$base}/{$path}");
                if ($response->status() === 200 && str_contains($response->body(), $needle)) {
                    $exposed[] = $path;
                }
            }
        } catch (Throwable) {
            return null;
        }

        return $exposed;
    }

    /**
     * TXT records of a DNS name. null = lookups unavailable (function disabled or the resolver failed).
     *
     * @return list<string>|null
     */
    protected function dnsTxt(string $name): ?array
    {
        if (! $this->functionAvailable('dns_get_record')) {
            return null;
        }
        $records = @dns_get_record($name, DNS_TXT);
        if ($records === false) {
            return null;
        }

        return array_map(fn (array $r) => (string) ($r['txt'] ?? ''), $records);
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function backupCheck(): array
    {
        $engine = (string) config('backup.dump_engine', 'auto');
        $proc = $this->functionAvailable('proc_open');
        $exec = $this->functionAvailable('exec');
        $funcs = 'proc_open '.($proc ? 'on' : 'off').', exec '.($exec ? 'on' : 'off');

        return match (true) {
            $engine === 'native' && ! $proc => ['Backup engine', self::FAIL, "BACKUP_DUMP_ENGINE=native needs proc_open ({$funcs}); set BACKUP_DUMP_ENGINE=php"],
            $engine === 'php' => ['Backup engine', self::OK, "php (PHP-only dumper; {$funcs})"],
            $engine === 'auto' && ! $proc => ['Backup engine', self::OK, "auto → php dumper ({$funcs})"],
            default => ['Backup engine', self::OK, "{$engine} ({$funcs})"],
        };
    }

    /**
     * P6-212: `juwri:preflight` reports the host's effective per-file upload cap.
     *
     * Read through `UploadLimit::effectiveMaxMb()` so the row uses the SAME number the
     * runtime gives a too-large POST (the 413 page) and the upload forms (the MB hint).
     * WARN when below `MIN_UPLOAD_MB` (a backup import at 500 MB cannot fit; a 20 MB PDF
     * even hits a tight 16 MB default). OK when at/above. When neither ini directive is
     * readable (rare; fresh PHP with no ini scan) the row is skipped.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function uploadCheck(): array
    {
        $mb = $this->uploadMaxMb();
        if ($mb < 0) {
            return ['Upload limit (host)', self::OK, 'Could not read upload_max_filesize / post_max_size from php.ini'];
        }
        if ($mb === 0) {
            return ['Upload limit (host)', self::OK, 'No upload cap reported'];
        }
        if ($mb < self::MIN_UPLOAD_MB) {
            return ['Upload limit (host)', self::WARN, "{$mb} MB per file (the app expects ".self::MIN_UPLOAD_MB.' MB or more). Ask your host to raise upload_max_filesize and post_max_size in php.ini.'];
        }

        return ['Upload limit (host)', self::OK, "{$mb} MB per file"];
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function cronCheck(string $profile): array
    {
        $age = $this->schedulerAge();
        $missing = $profile === 'docker' ? self::WARN : self::FAIL;
        if (($age === null || $age > 300) && $this->inMaintenance()) {
            // P6-135: `php artisan down` also stops the scheduler's heartbeat; on a freshly moved site (the maintenance flag travels
            // with the files, HOSTING.md section 4) a FAIL here would say "fix the cron" when the cron is fine.
            return ['Cron heartbeat', self::WARN, 'The site is in maintenance mode, so the scheduler pauses by design. Run `php artisan up`, wait a minute and run the preflight again'];
        }
        if ($age === null) {
            return ['Cron heartbeat', $missing, 'The scheduler has never run — add the cron: * * * * * php artisan schedule:run'];
        }
        if ($age > self::SCHEDULER_STALE) {
            return ['Cron heartbeat', $missing, "Last scheduler run {$age}s ago (expected every minute)"];
        }

        return ['Cron heartbeat', self::OK, "Last scheduler run {$age}s ago"];
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function workerCheck(string $profile): array
    {
        $alive = $this->daemonAlive();
        if ($profile === 'cpanel-vps' && ! $alive && $this->inMaintenance()) {
            // P6-135: a queue worker runs no job and stamps no heartbeat while the site is down (workers pause in maintenance mode).
            return ['Queue workers', self::WARN, 'The site is in maintenance mode, so the workers idle by design and show no heartbeat. Check `supervisorctl status`, run `php artisan up` and run the preflight again'];
        }

        return match ($profile) {
            'shared' => ['Queue workers', self::OK, 'cron drains the queues (queue:work --stop-when-empty)'],
            'cpanel-vps' => $alive
                ? ['Queue workers', self::OK, 'Dedicated worker heartbeat present']
                : ($this->binaryOnPath('supervisorctl') || $this->binaryOnPath('systemctl')
                    ? ['Queue workers', self::FAIL, 'No worker running — start the supervisor/systemd units (deploy/supervisor/)']
                    : ['Queue workers', self::FAIL, 'Neither supervisor nor systemd found; install one for the queue workers']),
            default => ['Queue workers', $alive ? self::OK : self::WARN, $alive ? 'Worker container heartbeat present' : 'No worker heartbeat yet (queue / queue-heavy containers)'],
        };
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function redisCheck(string $profile): array
    {
        $uses = array_keys(array_filter([
            'queue' => config('queue.default') === 'redis',
            'cache' => config('cache.default') === 'redis',
            'session' => config('session.driver') === 'redis',
        ]));
        if ($uses === []) {
            return ['Redis', self::OK, 'Not used'];
        }
        if ($profile === 'shared') {
            return ['Redis', self::FAIL, 'Redis set for '.implode(', ', $uses).' but the shared profile has no Redis; use database/file'];
        }
        $error = $this->redisPing();

        return $error === null
            ? ['Redis', self::OK, 'Reachable ('.implode(', ', $uses).')']
            : ['Redis', self::FAIL, 'Not reachable: '.$error];
    }

    /**
     * P6-145: a `database` session/cache/queue driver needs its tables, else the first request (or job) fails.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function databaseDriverChecks(): array
    {
        $cacheStore = (string) config('cache.default');
        $cache = (array) config("cache.stores.{$cacheStore}");
        $queueConnection = (string) config('queue.default');
        $queue = (array) config("queue.connections.{$queueConnection}");
        $checks = [
            'Session tables' => [config('session.driver') === 'database', 'SESSION_DRIVER', [(string) config('session.table', 'sessions')]],
            'Cache tables' => [($cache['driver'] ?? null) === 'database', 'CACHE_STORE', [(string) ($cache['table'] ?? 'cache'), (string) ($cache['lock_table'] ?? 'cache_locks')]],
            'Queue tables' => [($queue['driver'] ?? null) === 'database', 'QUEUE_CONNECTION', [
                (string) ($queue['table'] ?? 'jobs'), (string) config('queue.failed.table', 'failed_jobs'), (string) config('queue.batching.table', 'job_batches'),
            ]],
        ];
        $rows = [];
        foreach ($checks as $check => [$uses, $env, $tables]) {
            if (! $uses) {
                $rows[] = [$check, self::OK, 'Not used (driver is not database)'];

                continue;
            }
            $missing = $this->missingTables(array_values(array_unique($tables)));
            $rows[] = $missing === []
                ? [$check, self::OK, 'Present: '.implode(', ', $tables)]
                : [$check, self::FAIL, "{$env} uses the database driver but table(s) missing: ".implode(', ', $missing).' — run php artisan migrate'];
        }

        return $rows;
    }

    /**
     * P6-197: advice only — missing public/storage breaks media URLs but never the install, so it is
     * a WARN, not a blocker. The link can be either a real symlink (php artisan storage:link) or a
     * copied directory on hosts where symlink() is disabled (typical cPanel shared).
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function storageLinkCheck(): array
    {
        $path = public_path('storage');
        $ok = $this->storageLinkExists();

        return $ok
            ? ['Public storage link', self::OK, $path]
            : ['Public storage link', self::WARN, 'Run php artisan storage:link; on cPanel without symlink(), create a symlink to core/storage/app/public from the cPanel terminal (ln -s) or copy the folder to public/storage'];
    }

    /**
     * P6-119d: optional tools are warnings, never blockers (shared hosting cannot install them).
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function optionalToolCheck(string $check, ?string $reason, string $consequence): array
    {
        return $reason === null ? [$check, self::OK, 'Available'] : [$check, self::WARN, "{$reason}. {$consequence}"];
    }

    /** @return array{0: string, 1: string, 2: string} */
    private function mailCheck(): array
    {
        $mailer = (string) config('mail.default');
        $transport = (string) config("mail.mailers.{$mailer}.transport", $mailer);
        if (in_array($transport, ['log', 'array'], true)) {
            return ['Mail transport', self::WARN, "MAIL_MAILER={$mailer} sends nothing: set MAIL_MAILER=smtp and the MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD your mail provider gave you (deploy/EMAIL.md)"];
        }
        if ($transport !== 'smtp') {
            return ['Mail transport', self::OK, "{$transport} (not probed)"];
        }
        $host = (string) config("mail.mailers.{$mailer}.host");
        $port = (int) config("mail.mailers.{$mailer}.port", 587);
        $error = $this->tcpConnect($host, $port);

        return $error === null
            ? ['Mail transport', self::OK, "smtp {$host}:{$port} reachable"]
            : ['Mail transport', self::FAIL, "smtp {$host}:{$port} not reachable ({$error}); many shared hosts block outbound 25/587 — use the host's own SMTP"];
    }

    /**
     * P6-113: advice only (warn, never fail). SPF/DMARC of the sender domain and sender vs app domain.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function mailDomainChecks(): array
    {
        $mailer = (string) config('mail.default');
        $transport = (string) config("mail.mailers.{$mailer}.transport", $mailer);
        if (in_array($transport, ['log', 'array'], true)) {
            return [];
        }
        $from = (string) config('mail.from.address');
        $mailDomain = strtolower(trim((string) substr(strrchr($from, '@') ?: '', 1)));
        if ($mailDomain === '' || ! str_contains($mailDomain, '.') || $mailDomain === 'example.com') {
            return [['Mail sender domain', self::WARN, 'MAIL_FROM_ADDRESS has no real domain; set an address on your own domain (deploy/EMAIL.md)']];
        }
        $rows = [];
        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $appHost = preg_replace('/^www\./', '', $appHost) ?? $appHost;
        $related = $appHost !== '' && ($mailDomain === $appHost || str_ends_with($mailDomain, '.'.$appHost) || str_ends_with($appHost, '.'.$mailDomain));
        $rows[] = $related || $appHost === ''
            ? ['Mail sender domain', self::OK, "{$mailDomain} matches the app domain"]
            : ['Mail sender domain', self::WARN, "MAIL_FROM_ADDRESS domain {$mailDomain} differs from the app domain {$appHost}; publish SPF/DKIM/DMARC for {$mailDomain} (deploy/EMAIL.md)"];

        $spf = $this->txtRecords($mailDomain);
        $dmarc = $this->txtRecords('_dmarc.'.$mailDomain);
        if ($spf === null || $dmarc === null) {
            $rows[] = ['Mail records', self::WARN, 'SPF/DMARC not checked (DNS lookup unavailable or offline)'];

            return $rows;
        }
        $missing = [];
        if (! $this->hasRecord($spf, 'v=spf1')) {
            $missing[] = 'SPF';
        }
        if (! $this->hasRecord($dmarc, 'v=dmarc1')) {
            $missing[] = 'DMARC';
        }
        $rows[] = $missing === []
            ? ['Mail records', self::OK, "SPF and DMARC found for {$mailDomain}"]
            : ['Mail records', self::WARN, 'No '.implode(' or ', $missing)." record for {$mailDomain}; mail may land in spam (deploy/EMAIL.md)"];

        return $rows;
    }

    /** @param  list<string>  $records */
    private function hasRecord(array $records, string $prefix): bool
    {
        foreach ($records as $txt) {
            if (str_starts_with(strtolower(ltrim($txt)), $prefix)) {
                return true;
            }
        }

        return false;
    }

    /* ---- host probes (overridden in tests) ---- */

    /**
     * TXT records of a name, or null when DNS cannot be asked (offline / resolver error / timeout).
     * An empty list means the name answered with no TXT records.
     *
     * @return list<string>|null
     */
    protected function txtRecords(string $name): ?array
    {
        $resolver = (string) config('hosting.dns_resolver', '');
        $timeout = 3;
        $query = pack('nnnnnn', random_int(1, 65535), 0x0100, 1, 0, 0, 0);
        foreach (explode('.', $name) as $label) {
            $query .= chr(strlen($label)).$label;
        }
        $query .= "\0".pack('nn', 16, 1);
        $servers = $resolver !== '' ? [$resolver] : $this->systemResolvers();
        foreach ($servers as $server) {
            $sock = @stream_socket_client("udp://{$server}:53", $errno, $errstr, $timeout);
            if ($sock === false) {
                continue;
            }
            stream_set_timeout($sock, $timeout);
            fwrite($sock, $query);
            $reply = fread($sock, 4096);
            fclose($sock);
            if (! is_string($reply) || strlen($reply) < 12) {
                continue;
            }
            $parsed = $this->parseTxtReply($reply);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function systemResolvers(): array
    {
        $found = [];
        $conf = is_readable('/etc/resolv.conf') ? (string) file_get_contents('/etc/resolv.conf') : '';
        if (preg_match_all('/^nameserver\s+(\d{1,3}(?:\.\d{1,3}){3})\s*$/m', $conf, $m) > 0) {
            $found = $m[1];
        }

        return $found === [] ? ['1.1.1.1'] : array_slice($found, 0, 2);
    }

    /** @return list<string>|null null on a server failure (SERVFAIL/REFUSED); [] on NXDOMAIN/no data */
    private function parseTxtReply(string $reply): ?array
    {
        $flags = unpack('nflags/nqd/nan', substr($reply, 2, 6));
        if ($flags === false || ($flags['flags'] & 0x000F) > 3 || ($flags['flags'] & 0x000F) === 2) {
            return null;
        }
        $pos = 12;
        for ($i = 0; $i < $flags['qd']; $i++) {
            $pos = $this->skipName($reply, $pos) + 4;
        }
        $out = [];
        for ($i = 0; $i < $flags['an'] && $pos < strlen($reply); $i++) {
            $pos = $this->skipName($reply, $pos);
            $rr = unpack('ntype/nclass/Nttl/nlen', substr($reply, $pos, 10));
            if ($rr === false) {
                break;
            }
            $pos += 10;
            if ($rr['type'] === 16) {
                $data = substr($reply, $pos, $rr['len']);
                $txt = '';
                for ($o = 0; $o < strlen($data);) {
                    $l = ord($data[$o]);
                    $txt .= substr($data, $o + 1, $l);
                    $o += $l + 1;
                }
                $out[] = $txt;
            }
            $pos += $rr['len'];
        }

        return $out;
    }

    private function skipName(string $msg, int $pos): int
    {
        while ($pos < strlen($msg)) {
            $len = ord($msg[$pos]);
            if ($len === 0) {
                return $pos + 1;
            }
            if (($len & 0xC0) === 0xC0) {
                return $pos + 2;
            }
            $pos += $len + 1;
        }

        return $pos;
    }

    protected function phpVersion(): string
    {
        return PHP_VERSION;
    }

    protected function extensionLoaded(string $ext): bool
    {
        return extension_loaded($ext);
    }

    /** @return list<string> */
    protected function disabledFunctions(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', strtolower((string) ini_get('disable_functions'))))));
    }

    public function functionAvailable(string $name): bool
    {
        return function_exists($name) && ! in_array($name, $this->disabledFunctions(), true);
    }

    protected function writable(string $path): bool
    {
        return is_dir($path) && is_writable($path);
    }

    protected function headlessBrowserReason(): ?string
    {
        return app(HostCapabilities::class)->headlessBrowserReason();
    }

    protected function duckDbReason(): ?string
    {
        return app(HostCapabilities::class)->duckDbReason();
    }

    protected function schedulerAge(): ?int
    {
        return scheduler_last_run_age();
    }

    /**
     * P6-174: the scheduler heartbeat is fresh but comes only from the web cron running in process (no proc_open),
     * and the CLI cron has not run: module commands (CLI only) and big backups are skipped.
     */
    protected function webCronOnly(): bool
    {
        try {
            return scheduler_web_cron_only();
        } catch (Throwable) {
            return false; // a broken cache store is reported by its own check
        }
    }

    /** P6-174: the cPanel Cron Jobs line for this install (cPanel's PHP 8.4 CLI path; a bare `php` is often older). */
    public function cliCronLine(): string
    {
        return '* * * * * cd '.base_path().' && /opt/cpanel/ea-php84/root/usr/bin/php artisan schedule:run >> /dev/null 2>&1';
    }

    protected function inMaintenance(): bool
    {
        return app()->isDownForMaintenance();
    }

    protected function daemonAlive(): bool
    {
        return queue_daemon_alive();
    }

    protected function dockerMarkers(): bool
    {
        return is_file('/.dockerenv') || getenv('JUWRI_MIGRATE') !== false; // set on every service in docker/compose.prod.yml
    }

    protected function isRoot(): bool
    {
        return function_exists('posix_geteuid') && posix_geteuid() === 0;
    }

    protected function binaryOnPath(string $binary): bool
    {
        return DatabaseDump::binaryAvailable($binary);
    }

    /**
     * P6-212: how many MB this PHP host will accept per request.
     *
     * Tests override this method (returning -1 = unreadable, 0 = no cap, positive = MB)
     * instead of stubbing the global ini getters. Returns the same number
     * `UploadLimit::effectiveMaxMb()` returns at runtime.
     */
    protected function uploadMaxMb(): int
    {
        return UploadLimit::effectiveMaxMb();
    }

    /**
     * @param  list<string>  $tables
     * @return list<string>
     */
    protected function missingTables(array $tables): array
    {
        try {
            return array_values(array_filter($tables, fn (string $t) => ! Schema::hasTable($t)));
        } catch (Throwable) {
            return $tables; // database unreachable: every table counts as missing
        }
    }

    protected function redisPing(): ?string
    {
        try {
            Redis::connection()->ping();

            return null;
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * P6-197: a real symlink (php artisan storage:link) or a copied directory (cPanel without
     * symlink()) at public/storage. Overridden in tests.
     */
    protected function storageLinkExists(): bool
    {
        $path = public_path('storage');

        return is_link($path) || is_dir($path);
    }

    protected function tcpConnect(string $host, int $port): ?string
    {
        if ($host === '') {
            return 'MAIL_HOST is empty';
        }
        $socket = @fsockopen($host, $port, $errno, $errstr, 5);
        if ($socket === false) {
            return $errstr !== '' ? $errstr : "error {$errno}";
        }
        fclose($socket);

        return null;
    }
}
