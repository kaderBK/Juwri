<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use Tests\TestCase;

/**
 * O11 — guard against .env.example drift. Every env() key referenced in
 * core/config/*.php must be documented in .env.example, so a fresh prod box
 * never silently falls back to an undocumented default. Scoped to config/ (the
 * enforceable surface) — app-level env() reads are intentionally out of scope.
 */
class EnvExampleDriftTest extends TestCase
{
    /** Keys derived/composed at runtime that never appear as a raw .env line. */
    private const ALLOW = [
        // (none — all config env() keys are documented)
    ];

    private function configEnvKeys(): array
    {
        $keys = [];
        foreach (glob(base_path('config/*.php')) ?: [] as $file) {
            $src = (string) file_get_contents($file);
            if (preg_match_all("/env\(['\"]([A-Z0-9_]+)['\"]/", $src, $m)) {
                foreach ($m[1] as $k) {
                    $keys[$k] = true;
                }
            }
        }

        return array_keys($keys);
    }

    private function documentedKeys(): array
    {
        $keys = [];
        // A key is "documented" whether it is an active line (KEY=value) or a
        // commented placeholder (# KEY=) — the latter documents without
        // overriding a config default with an empty string.
        foreach (preg_split('/\r?\n/', (string) file_get_contents(base_path('.env.example'))) as $line) {
            if (preg_match('/^#?\s*([A-Z0-9_]+)=/', trim($line), $m)) {
                $keys[$m[1]] = true;
            }
        }

        return $keys;
    }

    public function test_every_config_env_key_is_documented(): void
    {
        $documented = $this->documentedKeys();

        $missing = [];
        foreach ($this->configEnvKeys() as $key) {
            if (! isset($documented[$key]) && ! in_array($key, self::ALLOW, true)) {
                $missing[] = $key;
            }
        }
        sort($missing);

        $this->assertSame([], $missing, 'undocumented config env() keys in .env.example: ' . implode(', ', $missing));
    }
}
