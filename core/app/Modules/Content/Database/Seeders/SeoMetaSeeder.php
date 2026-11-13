<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Content\Database\Seeders;

use Illuminate\Database\Seeder;
use Juwri\Core\Settings\PlatformSettings;

/**
 * SEO-growth W0.1 — sets the default-locale (Arabic on prod) home title &
 * description to the SERP-researched strings, WITHOUT touching the branding
 * `site_title` (which also feeds config app.name / emails). The home blade
 * prefers these dedicated options and falls back to site_title when unset, so
 * running this is safe and idempotent. Non-default locales are handled by the
 * translated ___() values in core/lang/*, not by this seeder.
 *
 * Run on production after the W0.1 deploy:
 *   php artisan db:seed --class=Juwri\\Modules\\Content\\Database\\Seeders\\SeoMetaSeeder
 */
class SeoMetaSeeder extends Seeder
{
    public function run(): void
    {
        $options = [
            'home_meta_title' => 'منيو الكتروني للمطاعم والكافيهات — طلبات واتساب وبدون عمولة | منيو باركود',
            'home_meta_description' => 'منيو الكتروني QR للمطاعم والكافيهات: انشاء منيو باركود مجاناً، طلبات واتساب، كاشير ونقاط بيع، وفاتورة الكترونية متوافقة مع زاتكا — بدون عمولة. جربها مجاناً.',
        ];

        // updateOptions is the app's single write choke point: it upserts by
        // option_name (no mass assignment) and busts the settings cache.
        foreach ($options as $name => $value) {
            PlatformSettings::set($name, $value);
        }
    }
}
