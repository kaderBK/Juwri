<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Settings\StoreSettings;

/**
 * J57 / J52b SF1: before J57 the hotel settings form saved `hotel_charge_room_token_required` = '0' whenever an owner
 * saved the page with the (then unchecked by default) box untouched, so that stored '0' is not an owner choice and
 * would keep J57's new default (token required) from ever reaching those hotels. This one-time migration removes the
 * stored value (`stores.settings` -> custom.hotel_charge_room_token_required) for every existing store, so the default
 * applies (HotelRoomToken::enabled: unset = required). An owner who really wants it off unticks it again after the
 * upgrade (release notes). The JSON is decoded and encoded in PHP (chunked), so the same code runs on SQLite, MySQL
 * and PostgreSQL. Re-run safe; down() cannot know which stores had opted out and leaves the settings as they are.
 */
return new class extends Migration
{
    private const NAME = 'hotel_charge_room_token_required';

    public function up(): void
    {
        if (! Schema::hasTable('stores') || ! Schema::hasColumn('stores', 'settings')) {
            return;
        }

        DB::table('stores')->whereNotNull('settings')->select(['id', 'settings'])->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $settings = json_decode((string) $row->settings, true);
                    if (! is_array($settings) || ! is_array($settings[StoreSettings::CUSTOM] ?? null)
                        || ! array_key_exists(self::NAME, $settings[StoreSettings::CUSTOM])) {
                        continue;
                    }
                    unset($settings[StoreSettings::CUSTOM][self::NAME]);
                    if ($settings[StoreSettings::CUSTOM] === []) {
                        unset($settings[StoreSettings::CUSTOM]);
                    }
                    DB::table('stores')->where('id', $row->id)->update([
                        'settings' => $settings === [] ? null : json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                    ]);
                }
            });

        StoreSettings::flush();
    }

    public function down(): void
    {
        // One-way: the stored '0' was not an owner choice (see above), so there is nothing to restore.
    }
};
