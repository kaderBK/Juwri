<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Settings\PlatformSettings;

/**
 * J52 (Opus review S1): the platform_settings keys PlatformSettingKeys renamed in the restaurant -> store rename that
 * 2026_10_20_100000_rename_legacy_schema_names did not move. Same rule as there: when the new name already has a row
 * the old row is dropped, otherwise the old row is renamed. Re-run safe; down() renames back the same way.
 */
return new class extends Migration
{
    /** @var array<string, string> old name => new name */
    private const RENAMES = [
        'email_message_post_notification' => 'email_message_store_notification',
        'email_sub_post_notification' => 'email_sub_store_notification',
        'fallback_post_template' => 'fallback_store_theme',
        'post_address_mode' => 'store_address_mode',
        'post_auto_approve' => 'store_auto_approve',
        'post_desc_editor' => 'store_desc_editor',
        'post_premium_listing' => 'store_premium_listing',
        'post_tags_mode' => 'store_tags_mode',
        'post_watermark' => 'store_watermark',
        'quickorder_enable' => 'store_order_enable',
        'quickorder_whatsapp_message' => 'store_order_whatsapp_message',
        'quickorder_homepage_enable' => 'store_order_homepage_enable',
    ];

    public function up(): void
    {
        $this->rename(self::RENAMES);
    }

    public function down(): void
    {
        $this->rename(array_flip(self::RENAMES));
    }

    /** @param  array<string, string>  $renames */
    private function rename(array $renames): void
    {
        if (! Schema::hasTable('platform_settings')) {
            return;
        }
        foreach ($renames as $old => $new) {
            if (DB::table('platform_settings')->where('name', $new)->exists()) {
                DB::table('platform_settings')->where('name', $old)->delete();
            } else {
                DB::table('platform_settings')->where('name', $old)->update(['name' => $new]);
            }
        }
        PlatformSettings::flush();
    }
};
