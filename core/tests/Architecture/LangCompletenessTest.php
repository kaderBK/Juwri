<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Every English key in lang/en/*.php must exist in every other locale, and a translated value must not be the English
 * text left in place (P6-89). For ar/ur/hi/ru/zh a value with no native-script letters at all (Latin only) is wrong.
 *
 * - missing keys: never allowed (no baseline).
 * - untranslated / wrong script: allowed only while listed in lang-completeness-baseline.json. The baseline can only
 *   shrink: an entry that is now fine must be removed from the file, a new problem fails the test.
 * - lang-completeness-allow.json lists ids (file|key) that are brand names, theme names or codes and stay as written.
 * - lang/{locale}.json has no English file (the key is the English text); its key set is the union over all locales.
 *
 * Regenerate the baseline with: LANG_BASELINE_WRITE=1 vendor/bin/phpunit --filter LangCompletenessTest
 */
class LangCompletenessTest extends TestCase
{
    public const LOCALES = ['ar', 'de', 'es', 'fr', 'hi', 'pt', 'ru', 'tr', 'ur', 'zh'];

    private const NON_LATIN = ['ar', 'hi', 'ru', 'ur', 'zh'];

    /** Brand names, units, acronyms and codes that are written the same in every language. */
    private const ALLOW = [
        'juwri', 'zatca', 'paytabs', 'stripe', 'paypal', 'whatsapp', 'telegram', 'google', 'apple', 'facebook', 'instagram',
        'tiktok', 'twitter', 'youtube', 'linkedin', 'snapchat', 'qr', 'pdf', 'csv', 'xml', 'json', 'api', 'url', 'sms', 'pin',
        'otp', 'html', 'css', 'svg', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'ssl', 'tls', 'dns', 'smtp', 'imap', 'ip', 'id',
        'vat', 'gst', 'ok', 'wifi', 'wi', 'fi', 'pos', 'kds', 'kot', 'pwa', 'ios', 'android', 'chrome', 'safari', 'firefox',
        'visa', 'mastercard', 'amex', 'apple', 'pay', 'gpay', 'mada', 'stc', 'upi', 'ussd', 'iban', 'swift', 'bic', 'gps',
        'uuid', 'utc', 'gmt', 'px', 'kb', 'mb', 'gb', 'kg', 'g', 'ml', 'cm', 'mm', 'kcal', 'hex', 'rgb', 'ai', 'eu', 'uk',
        'usa', 'uae', 'ksa', 'sar', 'usd', 'eur', 'gbp', 'aed', 'inr', 'try', 'egp', 'ee', 'oauth', 'sso', '2fa', 'mfa',
        'totp', 'webhook', 'webhooks', 'sqlite', 'mysql', 'redis', 'laravel', 'twilio', 'vonage', 'mailgun', 'sendgrid',
        'postmark', 'ses', 'pusher', 'zapier', 'shopify', 'woocommerce', 'uber', 'eats', 'deliveroo', 'talabat', 'jahez',
        'hungerstation', 'toyou', 'noon', 'careem', 'wolt', 'foodpanda', 'grubhub', 'doordash', 'tabby', 'tamara',
        'moyasar', 'hyperpay', 'checkout', 'razorpay', 'paystack', 'flutterwave', 'mollie', 'adyen', 'square', 'sumup',
        'clover', 'toast', 'lightspeed', 'epos', 'now', 'ar', 'de', 'es', 'fr', 'hi', 'pt', 'ru', 'tr', 'ur', 'zh', 'en',
        'am', 'pm', 'min', 'max', 'sku', 'gtin', 'ean', 'upc', 'nfc', 'rfid', 'usb', 'hdmi', 'cpu', 'ram', 'ssd', 'seo',
        'ux', 'ui', 'faq', 'faqs', 'ok', 'cc', 'bcc', 'to', 'etc', 'vs', 'kiosk', 'wc', 'tv', 'hd', 'fx', 'ocr', 'tax',
        'ip', 'asn', 'gdpr', 'ccpa', 'pci', 'dss', 'soc', 'iso', 'a', 'b', 'c', 'd', 'e', 'f', 'x', 'i', 'ii', 'iii',
    ];

    /** @return array<string,string> dotted key => value, for a lang/{locale}/{file}.php */
    private static function flat(array $a, string $p = ''): array
    {
        $o = [];
        foreach ($a as $k => $v) {
            if (is_array($v)) {
                $o += self::flat($v, $p.$k.'.');
            } else {
                $o[$p.$k] = (string) $v;
            }
        }

        return $o;
    }

    /** The words of a value that a translator would have to translate. */
    public static function words(string $v): array
    {
        $v = html_entity_decode(strip_tags($v));
        $v = preg_replace('~https?://\S+|\S+@\S+|:[A-Za-z_]+|\{[^}]*\}|%[a-z0-9$]+~', ' ', $v) ?? $v;
        preg_match_all('/[A-Za-z][A-Za-z0-9]*/', $v, $m);

        return array_values(array_filter($m[0], fn ($w) => ! in_array(strtolower($w), self::ALLOW, true) && strlen($w) > 1));
    }

    /** @return array<string,string> "file|key" => English value, from lang/en/*.php */
    private static function english(string $lang): array
    {
        $out = [];
        foreach (glob($lang.'/en/*.php') as $f) {
            foreach (self::flat(require $f) as $k => $v) {
                if (! str_starts_with($k, 'custom.')) { // Laravel's placeholder example, not a real message
                    $out[basename($f, '.php').'|'.$k] = $v;
                }
            }
        }

        return $out;
    }

    /** @return array{missing: array<string,list<string>>, untranslated: array<string,list<string>>} locale => ids */
    public static function scan(?string $lang = null): array
    {
        $lang ??= dirname(__DIR__, 2).'/lang';
        $en = self::english($lang);
        $res = ['missing' => [], 'untranslated' => []];
        $jsonAll = [];
        $json = [];
        foreach (self::LOCALES as $l) {
            $json[$l] = json_decode((string) file_get_contents("$lang/$l.json"), true) ?: [];
            $jsonAll += array_fill_keys(array_keys($json[$l]), true);
        }
        $keep = array_flip(json_decode((string) file_get_contents(__DIR__.'/lang-completeness-allow.json'), true) ?: []);
        foreach (self::LOCALES as $l) {
            $own = [];
            foreach (glob("$lang/$l/*.php") as $f) {
                foreach (self::flat(require $f) as $k => $v) {
                    $own[basename($f, '.php').'|'.$k] = $v;
                }
            }
            $res['missing'][$l] = [];
            $res['untranslated'][$l] = [];
            foreach ($en as $id => $ev) {
                if (! array_key_exists($id, $own)) {
                    $res['missing'][$l][] = $id;

                    continue;
                }
                if (! isset($keep[$id]) && self::untranslated($l, $ev, $own[$id])) {
                    $res['untranslated'][$l][] = $id;
                }
            }
            foreach (array_keys($jsonAll) as $k) {
                if (! array_key_exists($k, $json[$l])) {
                    $res['missing'][$l][] = 'json|'.$k;
                } elseif (! isset($keep['json|'.$k]) && self::untranslated($l, $k, (string) $json[$l][$k])) {
                    $res['untranslated'][$l][] = 'json|'.$k;
                }
            }
        }

        return $res;
    }

    public static function untranslated(string $locale, string $english, string $value): bool
    {
        if (self::words($english) === []) {
            return false;
        }
        $native = in_array($locale, self::NON_LATIN, true);
        if ($native && self::words($value) !== [] && ! preg_match('/[\p{Arabic}\p{Devanagari}\p{Cyrillic}\p{Han}]/u', $value)) {
            return true;
        }
        if ($value !== $english) {
            return false;
        }

        // Latin-script locales share many words with English (Menu, Table, Total): only flag phrases.
        return $native || count(self::words($english)) >= 2;
    }

    private static function baselinePath(): string
    {
        return __DIR__.'/lang-completeness-baseline.json';
    }

    /** @return array<string,list<string>> */
    private static function baseline(): array
    {
        return json_decode((string) file_get_contents(self::baselinePath()), true) ?: [];
    }

    public function test_every_english_key_exists_in_every_locale(): void
    {
        $missing = array_filter(self::scan()['missing']);
        $this->assertSame([], array_map(fn ($ids) => array_slice($ids, 0, 10), $missing), 'Keys missing from a locale (first 10 shown). Add real translations.');
    }

    public function test_untranslated_values_only_shrink_against_the_baseline(): void
    {
        $now = self::scan()['untranslated'];
        if (getenv('LANG_BASELINE_WRITE')) {
            ksort($now);
            file_put_contents(self::baselinePath(), json_encode($now, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        }
        $base = self::baseline();
        $new = [];
        $stale = [];
        foreach (self::LOCALES as $l) {
            if ($d = array_diff($now[$l], $base[$l] ?? [])) {
                $new[$l] = array_slice(array_values($d), 0, 10);
            }
            if ($d = array_diff($base[$l] ?? [], $now[$l])) {
                $stale[$l] = array_slice(array_values($d), 0, 10);
            }
        }
        $this->assertSame([], $new, 'New English-left or wrong-script values (first 10 per locale). Translate them.');
        $this->assertSame([], $stale, 'Fixed entries still listed in lang-completeness-baseline.json. Remove them (LANG_BASELINE_WRITE=1).');
    }
}
