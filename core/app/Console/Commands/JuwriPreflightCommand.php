<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Console\Commands;

use App\Support\HostingPreflight;
use App\Support\HostingProfile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * P6-123 (J40, absorbs P6-119b): check this host against a hosting profile. Exits 1 on any blocker.
 * The web PHP version is read through a one-shot token (5 min, single use, sent in a header — never
 * in the URL) on GET /_preflight/php; when APP_URL is not reachable the curl line is printed instead.
 */
class JuwriPreflightCommand extends Command
{
    public const TOKEN_CACHE_KEY = 'juwri_preflight_web_token';

    public const TOKEN_HEADER = 'X-Juwri-Preflight';

    protected $signature = 'juwri:preflight
        {--profile= : shared, cpanel-vps or docker (detected when omitted)}
        {--no-web : Skip the web PHP version probe}';

    protected $description = 'Check this host against a hosting profile (PHP 8.4 CLI+web, extensions, backups, cron, workers, Redis, mail).';

    public function handle(HostingPreflight $preflight): int
    {
        $profile = $this->resolveProfile($preflight);
        if ($profile === null) {
            $this->error('Unknown profile. Use one of: '.implode(', ', HostingProfile::PROFILES));

            return self::FAILURE;
        }
        $this->info("Hosting profile: {$profile}");

        [$web, $curl] = $this->option('no-web') ? [null, null] : $this->probeWeb();
        $rows = $preflight->run($profile, $web);

        $this->table(['Check', 'Status', 'Detail'], array_map(
            fn (array $r) => [$r['check'], strtoupper($r['status']), $r['detail']],
            $rows,
        ));
        if ($web === null && $curl !== null) {
            $this->line('Check the web PHP version by hand (token valid 5 minutes, one use):');
            $this->line('  '.$curl);
        }

        if (HostingPreflight::hasBlockers($rows)) {
            $this->error('Blockers found — fix the FAIL rows above.');

            return self::FAILURE;
        }
        $this->info('No blockers.');

        return self::SUCCESS;
    }

    private function resolveProfile(HostingPreflight $preflight): ?string
    {
        $given = $this->option('profile');
        if (is_string($given) && $given !== '') {
            return in_array($given, HostingProfile::PROFILES, true) ? $given : null;
        }
        $detected = config('hosting.profile_explicit') ? (string) config('hosting.profile') : $preflight->detectProfile();
        if (! $this->input->isInteractive()) {
            return $detected;
        }
        $profile = (string) $this->choice('Hosting profile (shared = cPanel without root, cpanel-vps = cPanel/WHM with root, docker)', HostingProfile::PROFILES, $detected);
        $this->offerEnvWrite($profile);

        return $profile;
    }

    /** Installer step (there is no web installer): store the chosen profile in .env when it is not set yet. */
    private function offerEnvWrite(string $profile): void
    {
        $path = app()->environmentFilePath();
        if (! is_file($path) || ! is_writable($path)) {
            return;
        }
        $env = (string) file_get_contents($path);
        if (preg_match('/^HOSTING_PROFILE=/m', $env) === 1) {
            return;
        }
        if ($this->confirm("Write HOSTING_PROFILE={$profile} to .env?", true)) {
            file_put_contents($path, rtrim($env, "\n")."\n\n# P6-123: hosting profile (shared|cpanel-vps|docker)\nHOSTING_PROFILE={$profile}\n");
            $this->info('Saved. Run `php artisan config:cache` if the config is cached.');
        }
    }

    /** @return array{0: ?string, 1: ?string} [web PHP version or null, curl line] */
    private function probeWeb(): array
    {
        $token = Str::random(40);
        try {
            Cache::put(self::TOKEN_CACHE_KEY, hash('sha256', $token), 300);
        } catch (Throwable) {
            return [null, null];
        }
        $url = rtrim((string) config('app.url'), '/').'/_preflight/php';
        $curl = "curl -s -H '".self::TOKEN_HEADER.": {$token}' {$url}";
        try {
            $response = Http::timeout(10)->withHeaders([self::TOKEN_HEADER => $token])->get($url);
            $version = $response->successful() ? $response->json('php') : null;
            if (is_string($version) && $version !== '') {
                return [$version, null];
            }
        } catch (Throwable) {
            // unreachable from the CLI (no loopback DNS, firewall): fall back to the printed curl line
        }

        // a failed attempt may have consumed the token: issue a fresh one for the curl line
        $token = Str::random(40);
        Cache::put(self::TOKEN_CACHE_KEY, hash('sha256', $token), 300);

        return [null, "curl -s -H '".self::TOKEN_HEADER.": {$token}' {$url}"];
    }
}
