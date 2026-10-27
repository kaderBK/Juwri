<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Messaging\Data;

/**
 * P1-08b follow-up (J27): message categories. A send's `feature` (otp|order|status|campaign|general) maps to the
 * WhatsApp/Meta pricing category; the cost ledger (`message_costs.category`) stores the short form
 * marketing|utility|service|auth for every channel.
 */
final class MessageCategory
{
    /** Feature → Meta category (moved here from Billing, which now reads it from here). */
    public const FEATURE_MAP = [
        'otp' => 'authentication',
        'order' => 'utility',
        'status' => 'utility',
        'campaign' => 'marketing',
        'general' => 'service',
    ];

    public const LEDGER = ['marketing', 'utility', 'service', 'auth'];

    /** Meta category of a feature (unknown features are free-form service messages). */
    public static function forFeature(string $feature): string
    {
        return self::FEATURE_MAP[$feature] ?? 'service';
    }

    /** The ledger form of a category (authentication → auth); unknown → service. */
    public static function ledger(string $category): string
    {
        $c = $category === 'authentication' ? 'auth' : $category;

        return in_array($c, self::LEDGER, true) ? $c : 'service';
    }
}
