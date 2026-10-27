<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * HPMS E13: platform-UI translation tooling — missing-string detection and JSON
 * language-pack export/import. Writes lang files DIRECTLY (the LanguageController
 * idiom) and NEVER through ___(), which would auto-register English into every
 * locale ([[lang-auto-register-trap]]). Keys are slugged the same way ___() slugs
 * them ([[lang-case-collision]]), so an imported key lands on the same row.
 *
 * The base path is injectable so tests never touch the real core/lang tree.
 */
class LanguagePackService
{
    public function __construct(private string $basePath)
    {
    }

    public static function forApp(): self
    {
        return new self(base_path('lang'));
    }

    /**
     * Read one locale's `lang.php` map (missing file yields an empty map).
     *
     * @return array<string,mixed>
     */
    public function read(string $code): array
    {
        $file = $this->basePath.'/'.$code.'/lang.php';
        if (! File::exists($file)) {
            return [];
        }
        $data = include $file;

        return is_array($data) ? $data : [];
    }

    /**
     * Keys present in the default locale but ABSENT or EMPTY in the target.
     *
     * @return array<string,array{en:mixed,current:mixed}>
     */
    public function missing(string $code, string $default = 'en'): array
    {
        $target = $this->read($code);
        $base   = $this->read($default);

        $out = [];
        foreach ($base as $key => $value) {
            $has = array_key_exists($key, $target);
            $cur = $has ? $target[$key] : null;
            if (! $has || trim((string) $cur) === '') {
                $out[$key] = ['en' => $value, 'current' => $cur];
            }
        }

        return $out;
    }

    /**
     * Export a locale's map for a JSON pack.
     *
     * @return array<string,mixed>
     */
    public function export(string $code): array
    {
        return $this->read($code);
    }

    /**
     * Import a decoded pack into a locale, merging over existing keys and writing
     * the file directly. Only scalar values are accepted; keys are slugged to the
     * ___() convention so they collide-merge instead of duplicating. Returns the
     * number of keys written.
     *
     * @param  array<string,mixed>  $pack
     */
    public function import(string $code, array $pack): int
    {
        $current = $this->read($code);
        $written = 0;

        foreach ($pack as $rawKey => $value) {
            if (! is_scalar($value)) {
                continue;
            }
            $key = Str::slug((string) $rawKey, '_') ?: (string) $rawKey;
            $current[$key] = (string) $value;
            $written++;
        }

        $this->write($code, $current);

        return $written;
    }

    /**
     * Direct file write (the LanguageController::translateUpdate idiom).
     *
     * @param  array<string,mixed>  $map
     */
    private function write(string $code, array $map): void
    {
        $dir = $this->basePath.'/'.$code;
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }
        File::put($dir.'/lang.php', "<?php \n return ".var_export($map, true).";\n");
    }
}
