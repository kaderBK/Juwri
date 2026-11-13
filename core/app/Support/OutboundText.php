<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Support;

/**
 * P6-106: one sanitiser for customer/owner-typed text that lands in plain-text channels (SMS and WhatsApp bodies and
 * template params, email subjects and from/reply-to display names). HTML email bodies are NOT run through this —
 * Blade already escapes them and a second pass would double-escape.
 *
 * Strips: control chars (CR/LF/tab included, except newlines in {@see text()}), every Unicode format char
 * (bidi overrides/isolates/marks, zero-width space, word joiner, BOM, tag chars) except ZWJ/ZWNJ, which emoji
 * sequences and Persian/Arabic shaping need; line/paragraph separators become spaces; whitespace collapses; length
 * is capped. Arabic and emoji are left intact.
 */
final class OutboundText
{
    /**
     * Format chars kept: ZWNJ/ZWJ (Persian shaping, emoji sequences), LRM/RLM/ALM (legitimate Arabic/Hebrew mixing
     * marks). Tag chars U+E0020–E007F are kept too (subdivision flags). Overrides U+202A–202E, isolates U+2066–2069,
     * zero-width space, word joiner, BOM etc. are stripped.
     */
    private const KEEP_FORMAT = ["\u{200C}", "\u{200D}", "\u{200E}", "\u{200F}", "\u{061C}"];

    /** Single-line field (name, address line, note excerpt) for SMS/WhatsApp/subject use. URLs are NOT touched. */
    public static function line(?string $value, int $max = 120): string
    {
        $s = self::strip((string) $value);
        $s = trim((string) preg_replace('/[\p{Z}\s]+/u', ' ', $s));

        return self::cap($s, $max);
    }

    /**
     * A person/store name placed inside an outbound message: {@see line()} plus URL neutralisation, so a name like
     * "Ahmed www.evil.com" cannot inject a clickable link into a store's own SMS ("Ahmed www evil com").
     */
    public static function name(?string $value, int $max = 60): string
    {
        $s = self::strip((string) $value);
        $s = (string) preg_replace('~\b[a-z][a-z0-9+.\-]{1,15}://~iu', ' ', $s);
        // Only an email-looking "@" (followed by host.tld) is spelled out; a bare "@" stays.
        $s = (string) preg_replace('~(?<=\S)@(?=[\p{L}\p{N}\-]+(?:\[?\.\]?)[\p{L}]{2,24}(?![\p{L}\p{N}]))~u', ' at ', $s);
        // Host-looking token (label.tld, TLD 2-24 letters, or www.) plus any path: dots/slashes become spaces.
        $s = (string) preg_replace_callback(
            '~(?<![\p{L}\p{N}_])(?:[\p{L}\p{N}\-]+(?:\[?\.\]?|\x{3002})){1,}\p{L}{2,24}(?![\p{L}\p{N}])(?:[/?#\\\\][^\s]*)?~u',
            static fn (array $m): string => (string) preg_replace('~\[?\.\]?|\x{3002}|[/\\\\]~u', ' ', $m[0]),
            $s,
        );
        $s = trim((string) preg_replace('/[\p{Z}\s]+/u', ' ', $s));

        return self::cap($s, $max);
    }

    /**
     * Multi-line message body: control/bidi/zero-width stripped, newlines kept (max one blank line). Runs of spaces are
     * left alone. No length cap by default: a body is never truncated (it could cut a URL or OTP); callers log instead.
     */
    public static function text(?string $value, ?int $max = null): string
    {
        $s = (string) $value;
        $s = str_replace(["\r\n", "\r", "\u{2028}", "\u{2029}"], "\n", $s);
        $s = self::strip($s, keepNewline: true);
        $s = (string) preg_replace('/\n{3,}/', "\n\n", $s);
        $s = trim($s);

        return $max === null ? $s : self::cap($s, $max);
    }

    /** One WhatsApp template parameter: single line, no tabs/newlines/runs of spaces (Meta rejects them). */
    public static function whatsAppParam(mixed $value, int $max = 400): string
    {
        return self::line(is_scalar($value) || $value === null ? (string) $value : '', $max);
    }

    /** Email subject / from-name: single line, no header-injection or spoofing characters. */
    public static function header(?string $value, int $max = 200): string
    {
        return self::line($value, $max);
    }

    private static function strip(string $s, bool $keepNewline = false): string
    {
        $s = (string) preg_replace('/[^\P{Cc}'.($keepNewline ? '\n' : '').']/u', ' ', $s);
        $s = (string) preg_replace_callback('/\p{Cf}/u', static function (array $m): string {
            $cp = mb_ord($m[0]);

            return in_array($m[0], self::KEEP_FORMAT, true) || ($cp >= 0xE0020 && $cp <= 0xE007F) ? $m[0] : '';
        }, $s);
        // ZWJ/ZWNJ only mean something between two visible characters.
        $s = (string) preg_replace('/(?<![\p{L}\p{N}\p{So}\p{Sk}\p{M}\x{FE0F}])[\x{200C}\x{200D}]+|[\x{200C}\x{200D}]+(?![\p{L}\p{N}\p{So}\p{Sk}\p{M}\x{FE0F}])/u', '', $s);

        return $s;
    }

    private static function cap(string $s, int $max): string
    {
        return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, max(1, $max - 1))).'…' : $s;
    }
}
