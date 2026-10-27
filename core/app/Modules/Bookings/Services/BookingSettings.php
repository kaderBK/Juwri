<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Bookings\Services;

use Juwri\Core\Settings\StoreSettings;

/**
 * P3-10: a store's booking settings with their defaults (typed `booking_*` keys in StoreSettingKeys).
 *
 * D8: deposits are refundable until the cancellation deadline (default 24 h) only when the store has turned deposit
 * refunds on (D7, off by default); the no-show fee is off by default. Reminders: email on, SMS and WhatsApp off until
 * the owner turns them on (the customer still opts in per booking).
 */
final class BookingSettings
{
    public const INTERVALS = [5, 10, 15, 20, 30, 60];

    public function __construct(
        public readonly int $storeId,
        public readonly int $slotInterval,
        public readonly int $leadMinutes,
        public readonly int $maxDays,
        public readonly int $cancelWindowHours,
        public readonly bool $depositRefunds,
        public readonly bool $noShowFee,
        public readonly int $noShowFeeMinor,
        public readonly bool $staffChoice,
        public readonly bool $atHome,
        public readonly int $atHomeRadiusKm,
        public readonly int $travelBufferMin,
        public readonly ?float $baseLat,
        public readonly ?float $baseLng,
        public readonly bool $reminderEmail,
        public readonly bool $reminderSms,
        public readonly bool $reminderWhatsApp,
        public readonly string $policyNote,
        public readonly string $timezone,
    ) {}

    public static function for(int $storeId): self
    {
        $interval = StoreSettings::int($storeId, 'booking_slot_interval', 15);
        $lat = StoreSettings::string($storeId, 'booking_base_lat');
        $lng = StoreSettings::string($storeId, 'booking_base_lng');

        return new self(
            storeId: $storeId,
            slotInterval: in_array($interval, self::INTERVALS, true) ? $interval : 15,
            leadMinutes: max(0, StoreSettings::int($storeId, 'booking_lead_minutes', 60)),
            maxDays: max(1, min(365, StoreSettings::int($storeId, 'booking_max_days', 60))),
            cancelWindowHours: max(0, min(720, StoreSettings::int($storeId, 'booking_cancel_window_hours', 24))),
            depositRefunds: StoreSettings::bool($storeId, 'booking_deposit_refunds', false),
            noShowFee: StoreSettings::bool($storeId, 'booking_no_show_fee', false),
            noShowFeeMinor: max(0, StoreSettings::int($storeId, 'booking_no_show_fee_minor', 0)),
            staffChoice: StoreSettings::bool($storeId, 'booking_staff_choice', true),
            atHome: StoreSettings::bool($storeId, 'booking_at_home', false),
            atHomeRadiusKm: max(1, min(500, StoreSettings::int($storeId, 'booking_at_home_radius_km', 10))),
            travelBufferMin: max(0, min(240, StoreSettings::int($storeId, 'booking_travel_buffer_min', 30))),
            baseLat: is_numeric($lat) ? (float) $lat : null,
            baseLng: is_numeric($lng) ? (float) $lng : null,
            reminderEmail: StoreSettings::bool($storeId, 'booking_reminder_email', true),
            reminderSms: StoreSettings::bool($storeId, 'booking_reminder_sms', false),
            reminderWhatsApp: StoreSettings::bool($storeId, 'booking_reminder_whatsapp', false),
            policyNote: StoreSettings::string($storeId, 'booking_policy_note'),
            timezone: store_tz($storeId),
        );
    }
}
