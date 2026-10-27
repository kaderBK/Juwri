<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Support;

/**
 * Display mask for stored secrets (gateway keys, SMTP passwords, API tokens).
 *
 * Settings screens render a secret as eight bullets plus, for long values, its
 * last four characters — enough to recognise which key is configured without
 * putting the key itself into the page. When the form is saved unchanged the
 * mask comes back in the request; the write paths (PlatformSettings::set,
 * StoreOption::updateStoreOption) treat any masked value as "keep the stored
 * secret" instead of persisting the bullets.
 *
 * Contract: a blank submission still clears the secret (unchanged behaviour);
 * only a value that starts with the bullet prefix is ignored.
 */
final class SecretMask
{
    /** Eight U+2022 bullets. No real credential starts with this. */
    public const PREFIX = '••••••••';

    /** Values at least this long also show their last four characters. */
    private const TAIL_MIN_LENGTH = 12;

    public static function mask(?string $plain): string
    {
        if ($plain === null || $plain === '') {
            return '';
        }

        $tail = mb_strlen($plain) >= self::TAIL_MIN_LENGTH ? mb_substr($plain, -4) : '';

        return self::PREFIX.$tail;
    }

    /** True when a submitted value is a mask echoed back by a settings form. */
    public static function isMasked(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, self::PREFIX);
    }
}
