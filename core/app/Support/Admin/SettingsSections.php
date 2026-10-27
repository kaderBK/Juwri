<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Support\Admin;

use Juwri\Modules\Messaging\Support\MailTemplateCatalog;
use Juwri\Modules\Payments\Support\GatewaySettings;

/**
 * The sections of Admin › Settings. Each section's form carries a hidden
 * marker field (`general_setting`, `smtp_settings`, ...) that says which
 * section is being saved; this class holds each section's rules and the
 * option names no section may write.
 *
 * Sections save the options their form posts. Option names that belong to
 * other screens (plans, plugins, payment gateways, mail templates) or that
 * must never be set from the web (debug) are refused, whatever is posted.
 */
final class SettingsSections
{
    /** Sections that only act (send a test mail, clear caches) and store nothing. */
    public const ACTIONS = ['smtp_test', 'clear_cache'];

    /** Marker field => rules for the section's fields. */
    private const SECTIONS = [
        'general_setting' => [
            'site_title' => 'required|string|max:255',
            'try_demo_link' => 'nullable|url',
            'termcondition_link' => 'nullable|url',
            'privacy_link' => 'nullable|url',
            'cookie_link' => 'nullable|url',
            // WI-R1: superadmin backup-import caps (blank/0 = shipped default).
            'admin_import_max_entries' => 'nullable|integer|min:1|max:1000000',
            'admin_import_max_uncompressed_mb' => 'nullable|integer|min:1|max:102400',
            // WA-S2: platform defaults for the WhatsApp hand-off.
            'whatsapp_handoff_mode' => 'nullable|in:auto,button,none',
            'whatsapp_handoff_target' => 'nullable|in:same,new',
            'whatsapp_handoff_delay' => 'nullable|numeric|min:0|max:5',
            'whatsapp_handoff_route' => 'nullable|in:branch,fixed',
            // H15: storefront order pipeline switch + canary store ids.
            'storefront_order_pipeline' => 'nullable|in:legacy,unified',
            'storefront_order_pipeline_stores' => 'nullable|string|max:2000|regex:/^[0-9,\s]*$/',
            // Marketing site WhatsApp support link (non-digits are stripped when shown).
            'support_whatsapp' => 'nullable|string|max:32',
        ],
        'theme_setting' => [
            'theme_color' => 'required|regex:/^#[A-Fa-f0-9]{6}$/',
            'contact_email' => 'nullable|email',
        ],
        'logo_setting' => [], // rules built in rules(): they depend on helper lists
        'international_setting' => [
            'currency_code' => ['required', 'string', 'max:4', 'regex:/^[A-Z]{3}$/'],
            'currency_sign' => ['required', 'string', 'max:4'],
            'currency_pos' => ['required', 'integer', 'min:0', 'max:1'],
            'timezone' => [], // in:<config('timezones')>, built in rules()
        ],
        'smtp_settings' => [
            'admin_email' => ['required', 'email'],
            'smtp_from_email' => ['required', 'email'],
            'smtp_from_name' => ['required'],
            'smtp_mailer' => ['required', 'in:smtp,sendmail,log'],
            'smtp_secure' => ['required', 'in:ssl,tls'],
        ],
        'smtp_test' => [
            'email' => ['required', 'email'],
        ],
        'billing_settings' => [],
        'social_logins_settings' => [],
        'blog_settings' => [],
        'testimonial_settings' => [],
        'custom_code_setting' => [],
        'addons_settings' => [],
        'wallet_topup_settings' => [
            'wallet_topup_enabled' => ['required', 'in:0,1'],
            'wallet_topup_min' => ['required', 'numeric', 'min:0.5'],
            'wallet_topup_max' => ['required', 'numeric', 'gte:wallet_topup_min'],
            'wallet_topup_presets' => ['nullable', 'string', 'max:120', 'regex:/^\s*\d+(\.\d+)?(\s*,\s*\d+(\.\d+)?)*\s*$/'],
        ],
        'clear_cache' => [],
    ];

    /** Image formats accepted for logos (mirrors the `accept` attrs in settings/logo.blade.php). */
    public const LOGO_MIMES = 'jpg,jpeg,png,webp,svg,gif,avif,bmp';

    /** Option names never written from the settings screen. */
    private const NEVER = [
        '_token', '_method', 'submit',
        // H3: APP_DEBUG / the old debug switch are deploy-time only.
        'juwri_debug', 'app_debug',
        // Owned by other screens.
        'free_membership_plan', 'trial_membership_plan', 'active_plugins',
    ];

    /**
     * The section a request saves: the first marker field it carries.
     *
     * @param  array<string, mixed>  $input
     */
    public static function detect(array $input): ?string
    {
        foreach (array_keys(self::SECTIONS) as $marker) {
            if (array_key_exists($marker, $input)) {
                return $marker;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    public static function rules(?string $section): array
    {
        if ($section === null) {
            return [];
        }

        $rules = self::SECTIONS[$section];

        if ($section === 'international_setting') {
            $rules['timezone'] = ['required', 'in:'.implode(',', array_keys((array) config('timezones')))];
        }

        if ($section === 'logo_setting') {
            $rules = [
                // Vector + animated + icon containers are stored verbatim by
                // StorageService (SVG sanitised first); the rest become WebP.
                'site_favicon' => 'nullable|mimes:'.self::LOGO_MIMES.',ico',
                'site_logo' => 'nullable|mimes:'.self::LOGO_MIMES,
                'site_logo_footer' => 'nullable|mimes:'.self::LOGO_MIMES,
                'site_admin_logo' => 'nullable|mimes:'.self::LOGO_MIMES,
                // Social crawlers do not render SVG cards: raster only.
                'social_share_image' => 'nullable|mimes:jpg,jpeg,png,webp,gif,avif,bmp',
                'brand_tile_style' => 'nullable|in:off,custom,'.implode(',', array_keys(brand_tile_presets())),
                'brand_tile_image' => 'nullable|mimes:'.self::LOGO_MIMES,
                'brand_tile_speed' => 'nullable|in:'.implode(',', array_keys(brand_tile_speeds())),
            ];
        }

        return $rules;
    }

    /** May the settings screen write this option name? */
    public static function writable(string $name): bool
    {
        static $refused = null;
        $refused ??= array_flip(array_merge(
            self::NEVER,
            array_keys(self::SECTIONS),
            GatewaySettings::allKeys(),
            MailTemplateCatalog::settingKeys(),
        ));

        return preg_match('/^[A-Za-z0-9_]{1,100}$/', $name) === 1 && ! isset($refused[$name]);
    }
}
