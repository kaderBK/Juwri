<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Support\DemoOwner;

use Illuminate\Support\Str;

/**
 * J62-1: the logins the owner hands-on demo hands out. A secret is generated once per run, on
 * first ask, and cached in the instance: every seeder that asks for the same key gets the same
 * secret, so the seeded rows and the credentials file always agree.
 *
 * A secret never leaves this class through the console: it is written to a markdown file with
 * mode 0600 ({@see write()}) and to nothing else. The command prints the file's path only.
 */
final class DemoCredentials
{
    /** @var array<string, string> */
    private array $passwords = [];

    /** @var array<string, string> */
    private array $pins = [];

    /** @var array<string, array{label: string, url: string, email: string, secret: string|null, kind: string}> */
    private array $rows = [];

    /** A strong password for $key (letters and digits only, so it copies cleanly out of a markdown table), generated once per run and never repeated inside the run. */
    public function password(string $key): string
    {
        if (isset($this->passwords[$key])) {
            return $this->passwords[$key];
        }

        do {
            $password = Str::password(28, true, true, false, false);
        } while (in_array($password, $this->passwords, true));

        return $this->passwords[$key] = $password;
    }

    /** A six digit PIN for $key, generated once per run and never repeated inside the run. */
    public function pin(string $key): string
    {
        if (isset($this->pins[$key])) {
            return $this->pins[$key];
        }

        do {
            $pin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (in_array($pin, $this->pins, true));

        return $this->pins[$key] = $pin;
    }

    /**
     * Collect one row of the credentials file. A label is written once: a later row with the same
     * label replaces the earlier one, so the seeder that owns a login has the final word.
     */
    public function record(string $label, string $url, string $email, ?string $secret, string $kind = 'password'): void
    {
        $this->rows[$label] = [
            'label' => $label,
            'url' => $url,
            'email' => $email,
            'secret' => $secret,
            'kind' => $kind,
        ];
    }

    /**
     * The collected rows, in the order they were first recorded.
     *
     * @return list<array{label: string, url: string, email: string, secret: string|null, kind: string}>
     */
    public function rows(): array
    {
        return array_values($this->rows);
    }

    /** Where the file goes: the configured path, else storage/app/demo-credentials.md. */
    public function path(): string
    {
        $configured = config('demo.credentials_path');
        $path = is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : storage_path(DemoOwnerPlan::CREDENTIALS_FILE);

        return $this->isAbsolute($path) ? $path : base_path($path);
    }

    /** Write the credentials file (mode 0600) and return its absolute path. */
    public function write(): string
    {
        $path = $this->path();
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        touch($path);
        chmod($path, 0600);
        file_put_contents($path, $this->render());

        return $path;
    }

    /** The markdown table the owner reads: label, URL, login, secret. */
    private function render(): string
    {
        $lines = [
            '# juwri demo credentials',
            '',
            'Written by `php artisan juwri:demo-seed` on '.now()->toDateTimeString().'. Synthetic demo data only (J19) — never reuse these logins on a real install.',
            '',
            '| Label | URL | Login | Secret |',
            '|---|---|---|---|',
        ];

        foreach ($this->rows() as $row) {
            $lines[] = '| '.$this->cell((string) $row['label'])
                .' | '.$this->cell((string) $row['url'])
                .' | '.$this->cell((string) $row['email'])
                .' | '.$this->cell((string) ($row['secret'] ?? ''))
                .' |';
        }
        $lines[] = '';

        return implode("\n", $lines);
    }

    /** One table cell: a pipe would break the table, a line break would break the row. */
    private function cell(string $value): string
    {
        return str_replace(['|', "\r", "\n"], ['\\|', ' ', ' '], $value);
    }

    private function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1;
    }
}
