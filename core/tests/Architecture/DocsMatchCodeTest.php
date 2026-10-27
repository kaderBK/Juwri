<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * P6-56: the operator docs (docs/, deploy/, docker/README.md) must not name an Artisan command or an environment key that
 * the code does not have. Commands are read from source (no boot), env keys from env() calls in core/ and from the docker
 * files. A command that only exists in the Enterprise package (the separate Enterprise package) must say "Enterprise" or "Cloud" near the mention.
 */
class DocsMatchCodeTest extends TestCase
{
    private const DOC_ROOTS = ['docs', 'deploy', 'docker/README.md', 'docker/VOICE-BRIDGE.md', 'site-copy', 'help'];

    /** Commands that ship with Laravel or a vendor package (not defined in our source). */
    private const VENDOR_COMMANDS = [
        'migrate' => 'Laravel', 'tinker' => 'laravel/tinker', 'test' => 'Laravel', 'serve' => 'Laravel',
        'config:cache' => 'Laravel', 'config:clear' => 'Laravel', 'cache:clear' => 'Laravel', 'view:cache' => 'Laravel',
        'route:cache' => 'Laravel', 'route:clear' => 'Laravel', 'storage:link' => 'Laravel', 'schedule:run' => 'Laravel',
        'key:generate' => 'Laravel', 'queue:restart' => 'Laravel', 'queue:work' => 'Laravel', 'queue:failed' => 'Laravel', 'migrate:status' => 'Laravel', 'db:wipe' => 'Laravel', 'down' => 'Laravel', 'up' => 'Laravel', 'reverb:start' => 'laravel/reverb',
    ];

    /** Env keys read by Laravel, PHP, Docker or the web server rather than by an env() call in core/. */
    private const EXTERNAL_KEYS = [
        // 'KEY' => 'reason it is read outside core/ and docker/ (e.g. by PHP or the OS)',
        'MINIO_KMS_SECRET_KEY' => 'read by the MinIO server (its own KMS setting), named in docs/ops/backup-restore.md',
        'TCP_IN' => 'ConfigServer Firewall (csf.conf) setting, named in deploy/VPS-FIRST-DAY.md',
        'UDP_IN' => 'ConfigServer Firewall (csf.conf) setting, named in deploy/VPS-FIRST-DAY.md',
        'TCP_OUT' => 'ConfigServer Firewall (csf.conf) setting, named in deploy/VPS-FIRST-DAY.md',
    ];

    public function test_documented_artisan_commands_exist(): void
    {
        $core = dirname(__DIR__, 2);
        [$community, $ee] = $this->commands($core);
        $problems = [];

        foreach ($this->docFiles(dirname($core)) as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
            foreach ($lines as $i => $line) {
                if (! preg_match_all('/php artisan ([a-z][a-z0-9:_-]*)/', $line, $m)) {
                    continue;
                }
                foreach ($m[1] as $cmd) {
                    $where = substr($file, strlen(dirname($core)) + 1).':'.($i + 1);
                    if (isset(self::VENDOR_COMMANDS[$cmd]) || isset($community[$cmd])) {
                        continue;
                    }
                    if (isset($ee[$cmd])) {
                        $near = implode(' ', array_slice($lines, max(0, $i - 2), 5));
                        if (! preg_match('/Enterprise|Cloud/i', $near)) {
                            $problems[] = "$where: `$cmd` is Enterprise-only; label it Enterprise/Cloud";
                        }

                        continue;
                    }
                    // The Community mirror has no Enterprise package, so its commands cannot be looked up there:
                    // a mention labelled Enterprise/Cloud is accepted when the package is absent.
                    if (! is_dir($core.'/ee') && preg_match('/Enterprise|Cloud/i', implode(' ', array_slice($lines, max(0, $i - 2), 5)))) {
                        continue;
                    }
                    $problems[] = "$where: `php artisan $cmd` is not a registered command";
                }
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }

    public function test_documented_env_keys_exist_in_code(): void
    {
        $core = dirname(__DIR__, 2);
        $known = $this->envKeys($core);
        $problems = [];

        foreach ($this->docFiles(dirname($core)) as $file) {
            $text = file_get_contents($file);
            preg_match_all('/`([A-Z][A-Z0-9]*(?:_[A-Z0-9]+)+)(?:=[^`]*)?`|^[ \t]*([A-Z][A-Z0-9]*(?:_[A-Z0-9]+)+)=/m', $text, $m);
            foreach (array_unique(array_filter(array_merge($m[1], $m[2]))) as $key) {
                if (! isset($known[$key]) && ! isset(self::EXTERNAL_KEYS[$key])) {
                    $problems[] = substr($file, strlen(dirname($core)) + 1).": `$key` is not read by any env() call or docker file";
                }
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }

    /** @return array{0: array<string,true>, 1: array<string,true>} [community, ee-only] */
    private function commands(string $core): array
    {
        $community = [];
        $ee = [];
        foreach (['app' => &$community, 'routes' => &$community, 'ee' => &$ee] as $dir => &$bucket) {
            foreach ($this->files($core.'/'.$dir, 'php') as $f) {
                $src = file_get_contents($f);
                if (preg_match_all('/\$signature\s*=\s*(?:\'([a-z][a-z0-9:_-]*)|"([a-z][a-z0-9:_-]*))/', $src, $m)
                    || preg_match_all('/Artisan::command\(\s*[\'"]([a-z][a-z0-9:_-]*)/', $src, $m)) {
                    foreach (array_filter(array_merge($m[1], $m[2] ?? [])) as $c) {
                        $bucket[$c] = true;
                    }
                }
                if (preg_match_all('/Artisan::command\(\s*[\'"]([a-z][a-z0-9:_-]*)/', $src, $m2)) {
                    foreach ($m2[1] as $c) {
                        $bucket[$c] = true;
                    }
                }
            }
        }

        return [$community, $ee];
    }

    /** @return array<string,true> */
    private function envKeys(string $core): array
    {
        $keys = [];
        foreach (['config', 'app', 'ee', 'bootstrap', 'routes', 'database'] as $dir) {
            foreach ($this->files($core.'/'.$dir, 'php') as $f) {
                if (preg_match_all('/env\(\s*[\'"]([A-Z][A-Z0-9_]*)[\'"]/', file_get_contents($f), $m)) {
                    foreach ($m[1] as $k) {
                        $keys[$k] = true;
                    }
                }
            }
        }
        foreach (glob(dirname($core).'/docker/*') ?: [] as $f) {
            if (is_file($f) && preg_match_all('/\b([A-Z][A-Z0-9]*(?:_[A-Z0-9]+)+)\b/', file_get_contents($f), $m)) {
                foreach ($m[1] as $k) {
                    $keys[$k] = true;
                }
            }
        }

        return $keys;
    }

    /** @return list<string> */
    private function docFiles(string $root): array
    {
        $out = [];
        foreach (self::DOC_ROOTS as $r) {
            $p = $root.'/'.$r;
            if (is_file($p)) {
                $out[] = $p;
            } elseif (is_dir($p)) {
                array_push($out, ...$this->files($p, 'md'));
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function files(string $dir, string $ext): array
    {
        if (! is_dir($dir)) {
            return is_file($dir) && str_ends_with($dir, '.'.$ext) ? [$dir] : [];
        }
        $out = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === $ext && ! str_contains($f->getPathname(), '/node_modules/')) {
                $out[] = $f->getPathname();
            }
        }

        return $out;
    }
}
