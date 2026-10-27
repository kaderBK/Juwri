<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Support;

use Symfony\Component\Process\ExecutableFinder;

/**
 * P6-119d (J37/J40): cheap, launch-free checks for the optional host tools. Shared hosting has no Node,
 * Chrome or DuckDB and often disables proc_open; callers hide/disable the feature with {@see self::MESSAGE_*}
 * instead of letting a Process call throw into a 500. Every probe returns null when the tool is usable, else a
 * plain-words reason.
 */
class HostCapabilities
{
    public const MESSAGE_EXPORT = 'PDF and image export is not available on this server. Use your browser\'s print function instead.';

    public const MESSAGE_DIRECTORY_IMPORT = 'Directory import needs the DuckDB tool, which is not installed on this server. Run the import on another machine.';

    public function processesAllowed(): bool
    {
        if (! function_exists('proc_open')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', strtolower((string) ini_get('disable_functions'))));

        return ! in_array('proc_open', $disabled, true);
    }

    protected function puppeteerInstalled(): bool
    {
        return is_dir(base_path('node_modules/puppeteer'));
    }

    public function nodePath(): ?string
    {
        foreach ([config('studio.export.node_path'), config('theme_studio.section_author.node')] as $configured) {
            if (is_string($configured) && $configured !== '' && @is_executable($configured)) {
                return $configured;
            }
        }

        return (new ExecutableFinder)->find('node', null, ['/usr/local/bin', '/opt/homebrew/bin', '/usr/bin']);
    }

    /** Studio PDF/PNG export and the AI section browser check (Node + puppeteer + Chrome). */
    public function headlessBrowserReason(): ?string
    {
        if (! $this->processesAllowed()) {
            return 'PHP process functions (proc_open) are disabled on this host';
        }
        if ($this->nodePath() === null) {
            return 'Node.js is not installed on this host';
        }
        if (! $this->puppeteerInstalled()) {
            return 'puppeteer is not installed (run npm install in core/)';
        }
        $chrome = config('studio.export.chrome_path');
        if ($chrome && ! @is_file((string) $chrome)) {
            return "the configured Chrome binary was not found: {$chrome}";
        }

        return null;
    }

    public function headlessBrowserAvailable(): bool
    {
        return $this->headlessBrowserReason() === null;
    }

    /** DuckDB CLI for the Overture directory import. */
    public function duckDbReason(): ?string
    {
        if (! $this->processesAllowed()) {
            return 'PHP process functions (proc_open) are disabled on this host';
        }
        $binary = (string) (config('directory.overture.duckdb_binary') ?: 'duckdb');
        $found = str_contains($binary, '/') ? (@is_executable($binary) ? $binary : null) : (new ExecutableFinder)->find($binary, null, ['/usr/local/bin', '/opt/homebrew/bin', '/usr/bin']);

        return $found === null ? "the {$binary} binary is not installed" : null;
    }

    public function duckDbAvailable(): bool
    {
        return $this->duckDbReason() === null;
    }
}
