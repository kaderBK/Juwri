<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Database\Seeders;

use App\Models\StoreOption;
use Illuminate\Database\Seeder;
use Juwri\Core\Models\Store;
use Juwri\Core\Money\Money;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Bookings\Models\BookableService;
use Juwri\Modules\Bookings\Models\BookingHour;
use Juwri\Modules\Bookings\Models\BookingStaff;

/**
 * P3-11 — the demo salon on the booking engine: Sidra Salon (KitDemoSeeder's `services` store) gets its services, staff roster,
 * weekly hours and booking settings from the same demo content (database/seeders/data/kit_demo/services.json), and renders the
 * `book` theme in its `calm` preset. Seeders only (J19).
 *
 * Idempotent: the store's booking rows are wiped and rebuilt on every run. KitDemoSeeder runs first when the salon is missing.
 * (KitDemoSeeder itself keeps the salon's menu items, the view-model goldens' source; this adds what the engine sells.)
 *
 *   php artisan db:seed --class='Juwri\Modules\Storefront\Database\Seeders\BookDemoSeeder'
 */
class BookDemoSeeder extends Seeder
{
    private const SLUG = 'sidra-salon-demo';

    private const WEEKDAYS = ['sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6];

    public function run(): void
    {
        if (Store::query()->where('slug', self::SLUG)->doesntExist()) {
            $this->call(KitDemoSeeder::class);
        }
        $store = Store::query()->where('slug', self::SLUG)->firstOrFail();
        $rid = (int) $store->id;
        $data = json_decode((string) file_get_contents(database_path('seeders/data/kit_demo/services.json')), true, 512, JSON_THROW_ON_ERROR);
        $s = $data['store'];
        $currency = (string) $s['currency'];

        BookableService::query()->where('store_id', $rid)->delete();
        BookingStaff::query()->where('store_id', $rid)->delete();
        BookingHour::query()->where('store_id', $rid)->delete();

        $booking = $s['booking'];
        StoreSettings::setMany($rid, [
            'booking_cancel_window_hours' => (int) $booking['cancel_window_hours'],
            'booking_slot_interval' => (int) $booking['slot_step_min'],
            'booking_deposit_refunds' => (bool) $booking['deposit_refundable'],
            'booking_staff_choice' => (bool) $booking['staff_choice'],
            'booking_at_home' => in_array('at_home', (array) $s['order_types'], true),
            'booking_at_home_radius_km' => (int) $booking['at_home_radius_km'],
            'booking_travel_buffer_min' => (int) $booking['travel_buffer_min'],
            'booking_lead_minutes' => 60,
            'booking_max_days' => 60,
            'booking_base_lat' => '24.7136',
            'booking_base_lng' => '46.6753',
        ]);
        StoreOption::updateStoreOption($rid, 'store_template', 'book');

        $services = [];
        $byCategory = [];
        foreach ($data['items'] as $position => $item) {
            $price = Money::minorFromMajor((float) $item['price'], $currency);
            $deposit = $item['deposit'];
            $service = BookableService::query()->create([
                'store_id' => $rid, 'name' => $item['name']['en'], 'description' => $item['description']['en'],
                'duration_min' => (int) $item['duration_min'], 'buffer_after_min' => (int) $item['buffer_min'],
                'price_minor' => $price, 'currency' => $currency,
                'deposit_type' => match ($deposit['type']) {
                    'fixed' => 'amount', 'percent' => 'percent', default => 'none'
                },
                'deposit_value' => match ($deposit['type']) {
                    'fixed' => Money::minorFromMajor((float) $deposit['value'], $currency), 'percent' => (int) $deposit['value'], default => 0
                },
                'venue' => true, 'at_home' => (bool) $item['at_home_available'], 'active' => true, 'position' => $position,
            ]);
            $services[$item['slug']] = $service;
            $byCategory[$item['category']][] = $service;
        }

        foreach ($data['staff'] as $member) {
            $mine = [];
            foreach ($member['service_categories'] as $category) {
                foreach ($byCategory[$category] ?? [] as $service) {
                    $mine[$service->id] = $service;
                }
            }
            $staff = BookingStaff::query()->create(['store_id' => $rid, 'name' => $member['name']['en'], 'role' => $member['role']['en'], 'active' => true, 'position' => (int) $member['position']]);
            $staff->services()->syncWithPivotValues(array_keys($mine), ['store_id' => $rid, 'account_id' => (int) $store->account_id]);
        }

        foreach ($s['hours']['weekly'] as $day => $ranges) {
            foreach ($ranges as [$open, $close]) {
                BookingHour::query()->create([
                    'store_id' => $rid, 'staff_id' => null, 'weekday' => self::WEEKDAYS[$day], 'kind' => 'open',
                    'starts_local' => (string) $open, 'ends_local' => $close === '00:00' ? '23:59' : (string) $close,
                ]);
            }
        }
    }
}
