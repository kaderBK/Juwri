<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Fiscal\Services\Zatca\Sdk;

/**
 * P6-150: turns the text the Fatoora SDK prints for `-validate -invoice <file>` into pass/fail plus its messages.
 * The SDK ends with "### Global validation result : PASSED|FAILED" and prints one line per ERROR / WARNING.
 * Anything that looks like a private key is redacted before it can reach the screen or a log.
 */
class SdkOutputParser
{
    /** @return array{status:string, messages:list<string>} status: PASS|FAIL|UNKNOWN */
    public static function parse(string $output): array
    {
        $text = self::redact(self::stripAnsi($output));
        $status = 'UNKNOWN';
        // P6-186: SDK 203 / CLI 3.0.8 prints "GLOBAL VALIDATION RESULT = PASSED|FAILED" and "[XSD|EN|KSA|…] validation result : …"
        if (preg_match('/Global validation result\s*[:=]\s*(PASSED|FAILED)/i', $text, $m)) {
            $status = strtoupper($m[1]) === 'PASSED' ? 'PASS' : 'FAIL';
        }
        if (preg_match('/validation result\s*[:=]\s*FAILED/i', $text)) {
            $status = 'FAIL';
        } elseif ($status === 'UNKNOWN' && preg_match('/validation result\s*[:=]\s*PASSED/i', $text)) {
            $status = 'PASS';
        }
        $messages = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $isFinding = preg_match('/\b(ERROR|WARN|WARNING)\b/', $line) === 1
                || preg_match('/validation result\s*:\s*FAILED/i', $line) === 1
                || preg_match('/^(Code|Category|Message)\s*:/i', $line) === 1;
            if ($isFinding && ! in_array($line, $messages, true)) {
                $messages[] = $line;
            }
        }
        // A run that printed no verdict and no finding (Java stack trace, bad path) is not a pass.
        if ($status === 'UNKNOWN' && $messages === [] && trim($text) !== '') {
            $messages[] = mb_substr(trim($text), 0, 400);
        }

        return ['status' => $status, 'messages' => $messages];
    }

    public static function stripAnsi(string $s): string
    {
        return (string) preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', $s);
    }

    /** Remove PEM private-key blocks and long base64 runs that follow "PRIVATE KEY". */
    public static function redact(string $s): string
    {
        return (string) preg_replace('/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(-----END [A-Z ]*PRIVATE KEY-----|\z)/s', '[private key removed]', $s);
    }
}
