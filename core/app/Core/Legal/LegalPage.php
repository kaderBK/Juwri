<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Legal;

use League\CommonMark\GithubFlavoredMarkdownConverter;
use RuntimeException;

/**
 * P1-23: one legal document as published — resources/legal/{en,ar}/<document>.md (English binding, Arabic for
 * convenience; numbering mirrors 1:1, so one template serves both). The markdown's own title, "Version … · Effective …"
 * line and Arabic "English prevails" quote are lifted out: the page shows them in its header strip (config version,
 * site.legal.* lines). {{FILL:key}} takes config('legal.fill.key'); {{VERIFY:key}} disappears once
 * config('legal.verify.key') is true. Anything unresolved is reported ({@see unresolved()}) and, outside production,
 * shown as a visible marker — `php artisan legal:check` and the production 503 keep it from shipping.
 */
final class LegalPage
{
    public const LOCALES = ['en', 'ar'];

    /** {{FILL:key}} (owner fact) / {{VERIFY:key}} (product promise) markers in the texts. */
    private const MARKER = '/\{\{(FILL|VERIFY):([a-z0-9_]+)\}\}/';

    /**
     * @param  list<string>  $unresolved  'FILL:key' / 'VERIFY:key'
     */
    private function __construct(
        public readonly string $document,
        public readonly string $locale,
        public readonly string $title,
        public readonly string $textVersion,
        public readonly string $textDate,
        public readonly string $body,
        public readonly array $unresolved,
    ) {}

    public static function path(string $document, string $locale): string
    {
        $override = config('legal.texts.'.$document);
        $root = is_string($override) && $override !== '' ? $override : self::root();

        return rtrim($root, '/').'/'.$locale.'/'.$document.'.md';
    }

    /** The directory holding the {en,ar}/*.md texts: resources/legal, or legal.source_path (tests only). */
    public static function root(): string
    {
        return (string) (config('legal.source_path') ?: resource_path('legal'));
    }

    /** The text locale shown for an app locale: Arabic where an Arabic text exists, English otherwise. */
    public static function textLocale(string $document, string $appLocale): string
    {
        return $appLocale === 'ar' && is_file(self::path($document, 'ar')) ? 'ar' : 'en';
    }

    public static function load(string $document, string $locale): self
    {
        if (! LegalDocuments::has($document) || ! in_array($locale, self::LOCALES, true)) {
            throw new RuntimeException("Unknown legal document [{$document}/{$locale}]");
        }
        $file = self::path($document, $locale);
        if (! is_file($file)) {
            throw new RuntimeException("Missing legal text {$file}");
        }

        return self::parse($document, $locale, (string) file_get_contents($file));
    }

    public static function parse(string $document, string $locale, string $markdown): self
    {
        $lines = preg_split('/\R/u', $markdown) ?: [];
        $title = '';
        $version = '';
        $date = '';
        // Header: "# Title", blank, "Version 1.0 · Effective 4 October 2026" (AR: "الإصدار 1.0 · ساري من …"),
        // optional "> translation note" quote, optional "---".
        while ($lines !== [] && trim($lines[0]) === '') {
            array_shift($lines);
        }
        if ($lines !== [] && str_starts_with($lines[0], '# ')) {
            $title = trim(substr((string) array_shift($lines), 2));
        }
        while ($lines !== [] && trim($lines[0]) === '') {
            array_shift($lines);
        }
        if ($lines !== [] && preg_match('/^(?:Version|الإصدار)\s+([0-9][0-9.]*)(?:\s+·\s+(?:Effective|ساري من)\s+(.+))?$/u', trim($lines[0]), $m) === 1) {
            // The date is optional (J51e): an announced version is dated from config/legal.php once the notice goes out.
            array_shift($lines);
            $version = $m[1];
            $date = trim($m[2] ?? '');
        }
        while ($lines !== [] && (trim($lines[0]) === '' || str_starts_with(ltrim($lines[0]), '>'))) {
            array_shift($lines);
        }
        if ($lines !== [] && trim($lines[0]) === '---') {
            array_shift($lines);
        }

        // J51b: before resolving markers, drop any Sub-processors table row whose vendor is marked
        // "Not used" (the whole row is removed, so its other markers never count as unresolved).
        $lines = array_values(array_filter($lines, fn (string $line): bool => ! LegalSettings::isUnusedLine($line)));

        $unresolved = [];
        $markdown = str_replace('{{SECURITY_REPORT}}', self::securityReport($locale), implode("\n", $lines));
        $text = (string) preg_replace_callback(self::MARKER, function (array $m) use (&$unresolved): string {
            [$all, $kind, $key] = $m;
            if ($kind === 'FILL') {
                $value = trim((string) config('legal.fill.'.$key, ''));
                if ($value !== '') {
                    return $value;
                }
            } elseif ((bool) config('legal.verify.'.$key, false)) {
                return '';
            }
            $unresolved[] = $kind.':'.$key;

            return '⟦'.$kind.':'.$key.'⟧';
        }, $markdown);

        $html = (string) (new GithubFlavoredMarkdownConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]))->convert($text);
        $html = (string) preg_replace('/⟦(FILL|VERIFY):([a-z0-9_]+)⟧/u', '<mark class="legal-todo" data-legal-todo="$1:$2">$1: $2</mark>', $html);

        return new self($document, $locale, $title, $version, $date, $html, array_values(array_unique($unresolved)));
    }

    /**
     * J71 (O1): where vulnerabilities are reported: GitHub private vulnerability reporting, no email address. The
     * `{{SECURITY_REPORT}}` token is a noun phrase; with LEGAL_SECURITY_REPORT_URL set it carries the link, without it
     * the phrase stays generic. It is never an unresolved marker, so the page is not hidden while the URL is unset.
     */
    public static function securityReport(string $locale): string
    {
        $url = SecurityReport::url();
        if (str_starts_with($locale, 'ar')) {
            return $url !== null ? "الإبلاغ الخاص عن الثغرات في GitHub على {$url}" : 'الإبلاغ الخاص عن الثغرات في GitHub على مستودعنا';
        }

        return $url !== null ? "GitHub private vulnerability reporting at {$url}" : 'GitHub private vulnerability reporting on our repository';
    }

    /**
     * Every FILL / VERIFY key the text uses (resolved or not).
     *
     * @return list<string>
     */
    public static function markers(string $document, string $locale): array
    {
        preg_match_all(self::MARKER, (string) file_get_contents(self::path($document, $locale)), $m, PREG_SET_ORDER);
        $out = array_map(fn (array $x) => $x[1].':'.$x[2], $m);
        sort($out);

        return array_values(array_unique($out));
    }
}
