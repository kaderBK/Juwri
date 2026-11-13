<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Agents\Services\Agents;

/**
 * Agents AO10 #50: multilingual auto-detect. A deterministic, script-based language
 * guess from the customer's message so the agent can reply in their language — no AI
 * call, no external service. Non-Latin scripts are unambiguous; a Latin message keeps
 * the conversation's current locale (we can't tell EN from FR/ES by script alone).
 */
class AgentLanguage
{
    /** code => human name (for the reply-in-language instruction). */
    public const NAMES = [
        'ar' => 'Arabic', 'en' => 'English', 'ru' => 'Russian', 'zh' => 'Chinese',
        'hi' => 'Hindi', 'he' => 'Hebrew', 'ur' => 'Urdu', 'fa' => 'Persian',
        'ja' => 'Japanese', 'ko' => 'Korean', 'el' => 'Greek',
        'fr' => 'French', 'es' => 'Spanish', 'de' => 'German', 'pt' => 'Portuguese',
        'it' => 'Italian', 'tr' => 'Turkish', 'nl' => 'Dutch',
    ];

    /**
     * Detect a language code from text. Returns $fallback when the script is Latin
     * (ambiguous) or the text carries no letters (digits/emoji only).
     */
    public static function detect(string $text, string $fallback = 'en'): string
    {
        // Ordered so more specific ranges win (Urdu/Persian before generic Arabic).
        $ranges = [
            'he' => '/\p{Hebrew}/u',
            'el' => '/\p{Greek}/u',
            'ru' => '/\p{Cyrillic}/u',
            'hi' => '/\p{Devanagari}/u',
            'ja' => '/[\x{3040}-\x{30ff}]/u',                 // Hiragana/Katakana
            'ko' => '/[\x{ac00}-\x{d7af}\x{1100}-\x{11ff}]/u', // Hangul
            'zh' => '/\p{Han}/u',
            // Urdu-EXCLUSIVE retroflex/aspirated letters. پ (067E) and چ (0686) are
            // shared with Persian, so they are deliberately NOT here — otherwise ordinary
            // Persian (which uses them) would be misdetected as Urdu.
            'ur' => '/[\x{0679}\x{0688}\x{0691}\x{06BA}\x{06BE}\x{06C1}\x{06D2}]/u',
            // Persian markers: gaf (06AF), farsi yeh (06CC), zhe (0698), plus pe/che.
            'fa' => '/[\x{067E}\x{0686}\x{0698}\x{06AF}\x{06CC}]/u',
            'ar' => '/\p{Arabic}/u',
        ];

        foreach ($ranges as $code => $pattern) {
            if (preg_match($pattern, $text)) {
                return $code;
            }
        }

        // Latin/ambiguous script → keep the caller's locale (verbatim), else English.
        return $fallback !== '' ? $fallback : 'en';
    }

    /** Human-readable name for a code (defaults to the code itself). */
    public static function name(string $code): string
    {
        return self::NAMES[$code] ?? $code;
    }
}
