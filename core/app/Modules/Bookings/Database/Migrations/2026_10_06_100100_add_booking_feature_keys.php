<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P3-10 (owner Q2, 2026-10-05): the booking engine is Community; Cloud plans can limit it with two plan keys —
 * `booking` (online bookings on the storefront) and `booking_api` (the headless booking API, P3-06b phase D).
 * Plan keys like the pricing build's (group `plan`, no default, not killable): a plan that holds no value for a key
 * keeps the feature (BookingPlan), and Community grants every key. Inserts only missing keys.
 */
return new class extends Migration
{
    /** @var array<string, array{label: string, description: string, upsell: array{0: string, 1: string}}> */
    private const KEYS = [
        'booking' => ['label' => 'Online Bookings', 'description' => 'Customers book services with staff and time slots on the storefront.', 'upsell' => ['Take bookings online', 'Let customers pick a service, a staff member and a free time, with deposits and reminders.']],
        'booking_api' => ['label' => 'Booking API', 'description' => 'The headless storefront API for staff, slots, holds and bookings.', 'upsell' => ['Book from your own app', 'Use the storefront API to show free times and take bookings in your own website or app.']],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('features')) {
            return;
        }
        $position = (int) DB::table('features')->max('position');
        $now = now();
        foreach (self::KEYS as $key => $def) {
            if (DB::table('features')->where('key', $key)->exists()) {
                continue;
            }
            DB::table('features')->insert([
                'key' => $key,
                'label' => $def['label'],
                'description' => $def['description'],
                'group_' => 'plan',
                'kind' => 'boolean',
                'default_value' => null,
                'sellable_via' => json_encode(['plan']),
                'addon_sku' => null,
                'platform_only' => false,
                'plan_gated' => false,
                'killable' => false,
                'shipped' => true,
                'tier' => 'core',
                'upsell_title' => $def['upsell'][0],
                'upsell_body' => $def['upsell'][1],
                'position' => ++$position,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('features')) {
            DB::table('features')->whereIn('key', array_keys(self::KEYS))->delete();
        }
    }
};
