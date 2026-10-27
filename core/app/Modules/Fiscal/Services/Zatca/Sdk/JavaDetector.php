<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Zatca\Sdk;

use Symfony\Component\Process\Process;

/**
 * P6-150: finds out which Java the ZATCA (Fatoora) SDK would run on. The SDK documents Java 11 up to 14.
 * parse() is pure so it can be tested against canned `java -version` text.
 */
class JavaDetector
{
    public const MIN = 11;

    public const MAX = 14;

    /** @return array{found:bool, major:?int, raw:string, ok:bool, level:string, advice:string} level: OK|WARN|FAIL */
    public static function parse(string $output): array
    {
        $raw = trim($output);
        if (! preg_match('/version "([^"]+)"/', $raw, $m)) {
            return self::result(false, null, $raw);
        }
        // "1.8.0_352" is Java 8; "11.0.21", "17.0.9" and "21" use the first number.
        $parts = explode('.', $m[1]);
        $major = (int) preg_replace('/\D.*/', '', $parts[0] === '1' ? ($parts[1] ?? '0') : $parts[0]);

        return self::result(true, $major, $raw);
    }

    /**
     * Runs `java -version` (Java prints it on stderr). A missing binary gives the "not found" result.
     *
     * @return array{found:bool, major:?int, raw:string, ok:bool, level:string, advice:string}
     */
    public static function detect(string $binary = 'java'): array
    {
        try {
            $p = new Process([$binary, '-version']);
            $p->setTimeout(15);
            $p->run();

            return self::parse($p->getErrorOutput().$p->getOutput());
        } catch (\Throwable) {
            return self::parse('');
        }
    }

    public static function installAdvice(): string
    {
        return 'Install Java 11 to 14 (OpenJDK is fine): macOS "brew install openjdk@11", Ubuntu/Debian "sudo apt install openjdk-11-jre-headless", '
            .'Windows: download OpenJDK 11 from adoptium.net. Then open a new terminal and check "java -version".';
    }

    /** @return array{found:bool, major:?int, raw:string, ok:bool, level:string, advice:string} */
    private static function result(bool $found, ?int $major, string $raw): array
    {
        if (! $found) {
            return ['found' => false, 'major' => null, 'raw' => $raw, 'ok' => false, 'level' => 'FAIL', 'advice' => 'Java was not found. '.self::installAdvice()];
        }
        if ($major < self::MIN) {
            return ['found' => true, 'major' => $major, 'raw' => $raw, 'ok' => false, 'level' => 'FAIL',
                'advice' => "Java {$major} is too old. The ZATCA SDK needs Java ".self::MIN.' to '.self::MAX.'. '.self::installAdvice()];
        }
        if ($major > self::MAX) {
            return ['found' => true, 'major' => $major, 'raw' => $raw, 'ok' => true, 'level' => 'WARN',
                'advice' => "Java {$major} is newer than the Java ".self::MIN.' to '.self::MAX.' the SDK documents. It may still run; if the SDK will not start, install Java '.self::MAX.' (or 11).'];
        }

        return ['found' => true, 'major' => $major, 'raw' => $raw, 'ok' => true, 'level' => 'OK', 'advice' => ''];
    }
}
