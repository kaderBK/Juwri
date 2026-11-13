<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Services\Telephony;

use Juwri\Modules\Agents\Models\PhoneLine;

/**
 * P6-09 V-09 (spec §8): card data never reaches the model or a log on a phone call. Speech-to-text text is cut BEFORE
 * the LLM, the agent_messages row and the call transcript see it.
 *
 * A "digit run" is a sequence of digit tokens joined only by spaces / dashes / dots / commas / slashes: Western digits,
 * Arabic-Indic (٠-٩) and Persian (۰-۹) digits, and spoken digits in English ("four two four two", "double four",
 * "oh") and Arabic ("أربعة اثنين", with a leading و). A run is replaced by [card] when:
 *   - it has 13 or more digits (a PAN is 13–19; longer runs are cut too), or
 *   - the utterance talks about a card (card / visa / cvv / expiry / بطاقة / فيزا ...) and the run has 3 or more
 *     digits (security code, expiry, partial PAN).
 * So a phone number (≤ 12 digits for KSA/UAE/US) and an order total or quantity stay usable when no card is mentioned.
 */
final class PciDigitFilter
{
    public const MARK = '[card]';

    private const PAN_DIGITS = 13;

    private const CONTEXT_DIGITS = 3;

    /** Spoken digit words → digit string. Arabic keys are alef-normalised (أ/إ/آ → ا). */
    private const WORDS = [
        'zero' => '0', 'oh' => '0', 'o' => '0', 'nought' => '0', 'one' => '1', 'two' => '2', 'three' => '3',
        'four' => '4', 'five' => '5', 'six' => '6', 'seven' => '7', 'eight' => '8', 'nine' => '9',
        'ten' => '10', 'eleven' => '11', 'twelve' => '12', 'thirteen' => '13', 'fourteen' => '14', 'fifteen' => '15',
        'sixteen' => '16', 'seventeen' => '17', 'eighteen' => '18', 'nineteen' => '19', 'twenty' => '20',
        'thirty' => '30', 'forty' => '40', 'fifty' => '50', 'sixty' => '60', 'seventy' => '70', 'eighty' => '80',
        'ninety' => '90',
        'صفر' => '0', 'واحد' => '1', 'واحده' => '1', 'واحدة' => '1', 'اثنين' => '2', 'اثنان' => '2', 'ثنين' => '2',
        'اتنين' => '2', 'ثلاثة' => '3', 'ثلاثه' => '3', 'ثلاث' => '3', 'تلاتة' => '3', 'تلاته' => '3', 'اربعة' => '4',
        'اربعه' => '4', 'اربع' => '4', 'خمسة' => '5', 'خمسه' => '5', 'خمس' => '5', 'ستة' => '6', 'سته' => '6',
        'ست' => '6', 'سبعة' => '7', 'سبعه' => '7', 'سبع' => '7', 'ثمانية' => '8', 'ثمانيه' => '8', 'ثمان' => '8',
        'تمانية' => '8', 'تمانيه' => '8', 'تسعة' => '9', 'تسعه' => '9', 'تسع' => '9',
    ];

    /** "double four" = 44, "triple oh" = 000. */
    private const REPEATS = ['double' => 2, 'triple' => 3];

    private const CONTEXT = '/\b(card|cards|visa|master\s*card|mastercard|amex|american\s+express|mada|cvv|cvc|cvv2|'
        .'security\s+code|expiry|expiration|expires|credit|debit)\b|بطاق|فيزا|ماستر|مدى|ائتمان|انتهاء|الرمز|رمز/iu';

    /** @return array{text: string, redacted: bool} */
    public static function filter(string $text): array
    {
        if ($text === '') {
            return ['text' => $text, 'redacted' => false];
        }
        $already = str_contains($text, self::MARK);
        $text = strtr($text, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
        $threshold = preg_match(self::CONTEXT, $text) === 1 ? self::CONTEXT_DIGITS : self::PAN_DIGITS;

        preg_match_all('/\d+|[\p{L}\p{M}]+|[^\d\p{L}\p{M}]+/u', $text, $m);
        $out = '';
        $redacted = $already;
        $run = '';      // the open run: digit tokens + joiners
        $joiners = '';  // joiners seen after the last digit token (they go back out if the run ends)
        $digits = 0;
        $repeat = 1;
        foreach ([...$m[0], ''] as $token) { // '' closes the last run
            $value = $token === '' ? null : self::digitsOf($token);
            $times = self::REPEATS[mb_strtolower($token)] ?? null;
            if ($value !== null) {
                $run .= $joiners.$token;
                $joiners = '';
                $digits += strlen($value) * $repeat;
                $repeat = 1;

                continue;
            }
            if ($times !== null) {
                $joiners .= $token;
                $repeat = $times;

                continue;
            }
            if (($run !== '' || $joiners !== '') && $token !== '' && preg_match('/^[\s\-–—.,\/]+$/u', $token) === 1) {
                $joiners .= $token;

                continue;
            }
            if ($run !== '') {
                $cut = $digits >= $threshold;
                $redacted = $redacted || $cut;
                $out .= $cut ? self::MARK : $run;
            }
            $out .= $joiners.$token;
            $run = '';
            $joiners = '';
            $digits = 0;
            $repeat = 1;
        }

        return ['text' => $redacted ? (string) preg_replace('/(?:\[card\][\s\-–—.,\/]*)+\[card\]/u', self::MARK, $out) : $text, 'redacted' => $redacted];
    }

    /** What the agent says instead of taking card details (in the line's locale when one is given). */
    public static function reply(PhoneLine|string|null $line = null): string
    {
        $say = fn (): string => ___('I can’t take card details by phone. I’ll text you a secure payment link instead.');
        if ($line instanceof PhoneLine) {
            return (string) VoiceLocale::with($line, $say);
        }
        if (is_string($line) && $line !== '') {
            return (string) VoiceLocale::withLocale($line, $say);
        }

        return $say();
    }

    /** The digits a token stands for, or null when it is not a digit token. */
    private static function digitsOf(string $token): ?string
    {
        if (ctype_digit($token)) {
            return $token;
        }
        $word = strtr(mb_strtolower($token), ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا']);
        if (isset(self::WORDS[$word])) {
            return self::WORDS[$word];
        }
        // Arabic "and four" is written joined: وأربعة
        if (mb_strlen($word) > 2 && str_starts_with($word, 'و') && isset(self::WORDS[mb_substr($word, 1)])) {
            return self::WORDS[mb_substr($word, 1)];
        }

        return null;
    }
}
