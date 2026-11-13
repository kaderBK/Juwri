<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Minishlink\WebPush\VAPID;

/**
 * PARITY-RUN follow-up #4 — generate the VAPID key pair that turns the staff
 * PWA's web push on.
 *
 * T9 bound a real `WebPushSender`, but `VapidWebPushSender` no-ops when the keys
 * are empty (deliberately — an empty key must never prune subscriptions). So on
 * an install with no keys, web push is wired but silent. This mints the pair.
 *
 * ── The private key is a secret, and this command treats it like one ──────────
 * By default it writes both keys straight into `.env` and prints ONLY the public
 * key. The private key never reaches stdout, a log, or this command's output —
 * the same discipline `key:generate` uses for APP_KEY. `--show` overrides that
 * for a secrets-manager workflow, with a loud warning, because a private key
 * printed to a terminal is a private key in someone's scrollback.
 *
 * ── Rotation is destructive ───────────────────────────────────────────────────
 * The public key IS the browser's `applicationServerKey`; every existing push
 * subscription was signed against the current pair. Replacing the keys makes
 * every one of them fail to decrypt — every staff device must re-subscribe. So a
 * store that already has keys is refused unless `--force`, and even then warned.
 *
 * Usage:
 *   php artisan push:vapid-keys
 *   php artisan push:vapid-keys --show          # also print the private key (careful)
 *   php artisan push:vapid-keys --print-only     # emit, do not touch .env
 *   php artisan push:vapid-keys --force          # rotate existing keys (destructive)
 */
class GenerateVapidKeys extends Command
{
    protected $signature = 'push:vapid-keys
        {--force      : Overwrite existing keys (invalidates every current subscription)}
        {--show       : Also print the private key — only for a secrets manager}
        {--print-only : Print the keys and do NOT write .env}
        {--subject=   : VAPID subject (mailto: or https:); defaults to the current one}';

    protected $description = 'Generate the VAPID key pair for staff web push. Writes .env; the private key is never printed by default.';

    public function handle(): int
    {
        if (! class_exists(VAPID::class)) {
            $this->error('minishlink/web-push is not installed.');

            return self::FAILURE;
        }

        $hasPublic = (string) config('webpush.public_key') !== '';
        $hasPrivate = (string) config('webpush.private_key') !== '';

        if (($hasPublic || $hasPrivate) && ! $this->option('force') && ! $this->option('print-only')) {
            $this->warn('This install already has VAPID keys.');
            $this->line('  Rotating them invalidates EVERY existing push subscription — each staff device');
            $this->line('  must re-subscribe. Re-run with --force only if you mean to do that.');

            return self::FAILURE;
        }

        $keys = VAPID::createVapidKeys();               // ['publicKey' => …, 'privateKey' => …]
        $subject = (string) ($this->option('subject') ?: config('webpush.subject') ?: 'mailto:admin@example.com');

        $this->line('');
        $this->info('VAPID key pair generated.');
        $this->line('');
        $this->line('  <fg=green>Public key</> (safe to expose — this is the browser applicationServerKey):');
        $this->line('    '.$keys['publicKey']);
        $this->line('');

        if ($this->option('show')) {
            $this->warn('  Private key (SECRET — anyone with it can push to your users):');
            $this->line('    '.$keys['privateKey']);
            $this->line('');
        } else {
            $this->line('  Private key: <fg=yellow>hidden</> ('.$this->masked($keys['privateKey']).') — pass --show to reveal it.');
            $this->line('');
        }

        if ($this->option('print-only')) {
            $this->comment('  --print-only: .env was NOT changed. Put these three where your config reads them:');
            $this->line('    VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY, VAPID_SUBJECT');
            $this->line('');

            return self::SUCCESS;
        }

        $written = $this->writeEnv([
            'VAPID_PUBLIC_KEY'  => $keys['publicKey'],
            'VAPID_PRIVATE_KEY' => $keys['privateKey'],
            'VAPID_SUBJECT'     => $subject,
        ]);

        if (! $written) {
            $this->error('  Could not write .env — set the three keys by hand (use --print-only to see them).');

            return self::FAILURE;
        }

        $this->info('  Written to '.$this->envPath().' — public key, private key (hidden) and subject.');
        $this->line('');
        $this->comment('  Next: clear config cache if you cache it (php artisan config:clear), then a staff');
        $this->comment('  device must (re)subscribe from the PWA before it can receive a push.');
        $this->line('');

        return self::SUCCESS;
    }

    private function masked(string $secret): string
    {
        $len = strlen($secret);

        return $len <= 6 ? str_repeat('•', $len) : substr($secret, 0, 3).str_repeat('•', $len - 6).substr($secret, -3);
    }

    private function envPath(): string
    {
        return app()->environmentFilePath();
    }

    /**
     * Replace or append each key in .env, in place. Values are quoted because a
     * VAPID key contains `/`, `+` and `_`; base64url has no quote-breaking chars
     * but quoting is the safe default Laravel itself uses.
     *
     * @param array<string,string> $pairs
     */
    private function writeEnv(array $pairs): bool
    {
        $path = $this->envPath();
        if (! is_file($path) || ! is_writable($path)) {
            return false;
        }

        $env = file_get_contents($path);
        foreach ($pairs as $key => $value) {
            $line = $key.'="'.$value.'"';
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
            if (preg_match($pattern, $env)) {
                $env = preg_replace($pattern, $line, $env);
            } else {
                $env = rtrim($env, "\n")."\n".$line."\n";
            }
        }

        return file_put_contents($path, $env) !== false;
    }
}
