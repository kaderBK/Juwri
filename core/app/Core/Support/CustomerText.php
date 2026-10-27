<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

/**
 * Deterministic checks on text written by customers (chat messages, reviews) before it is stored or sent to an AI
 * model. Shared by every module: the agent channels (Agents PiiRedactor / AgentSafety delegate here), the Google
 * review reply drafter (Integrations) and any other place customer text reaches a prompt.
 */
final class CustomerText
{
    /**
     * Replace personal data with placeholders: card-like numbers -> [card], e-mail -> [email], phone-like -> [phone].
     */
    public static function redactPii(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        // Card-like: 13–19 digits, optionally grouped by spaces/dashes. Do this
        // first so it wins over the shorter phone pattern.
        $text = (string) preg_replace('/\b(?:\d[ -]?){13,19}\b/', '[card]', $text);

        // Email.
        $text = (string) preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email]', $text);

        // Phone-like: an optional leading +, then 7–15 digits with spaces/dashes/parens.
        return (string) preg_replace('/\+?\d[\d ().\-]{6,}\d/', '[phone]', $text);
    }

    /** Obvious abuse: slurs/profanity or spam (a char/word hammered many times). */
    public static function isAbusive(string $text): bool
    {
        $t = mb_strtolower(trim($text));
        if ($t === '') {
            return false;
        }
        // Spam: same character run 15+ long, or one short token repeated 8+ times.
        if (preg_match('/(.)\1{14,}/u', $t)) {
            return true;
        }
        if (preg_match('/\b(\w{1,12})\b(?:\s+\1\b){7,}/u', $t)) {
            return true;
        }
        // Small profanity/slur set (word-boundary). Kept minimal + non-exhaustive;
        // the goal is a cheap first filter, not a moderation product.
        $words = ['fuck', 'shit', 'bitch', 'asshole', 'bastard', 'cunt', 'dick', 'retard'];

        return array_any($words, fn ($w) => (bool) preg_match('/\b'.preg_quote($w, '/').'\b/u', $t));
    }
}
