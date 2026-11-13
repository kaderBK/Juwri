<?php

// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
|--------------------------------------------------------------------------
| Website legal pages and acceptance records (P1-23)
|--------------------------------------------------------------------------
|
| JUWRI LTD's legal documents for juwri Cloud (owner decision 2026-10-04, "website legal pages v1.0"). The
| texts live in resources/legal/{en,ar}/<document>.md (English binding, Arabic for convenience) and render
| from one template at the paths below. They are JUWRI LTD's documents: a self-hosted instance that is not
| run by JUWRI LTD keeps LEGAL_PAGES=false (no /terms etc., no JUWRI footer line, no acceptance records).
|
| {{FILL:key}} markers in the texts take their value from `fill` (owner facts); {{VERIFY:key}} markers mark
| a product promise and disappear once `verify.<key>` is true. `php artisan legal:check` fails while any
| marker is unresolved, and a production request never renders one (503 instead).
|
*/

return [

    'enabled' => (bool) env('LEGAL_PAGES', false),

    'free_reseller_1_1_effective' => env('LEGAL_FREE_RESELLER_1_1_EFFECTIVE') ?: null,

    // Directory holding the {en,ar}/*.md texts; null = resources/legal. Tests point it at a copy with open markers.
    'source_path' => null,

    // In production a legal text with an unresolved {{FILL}} / {{VERIFY}} marker is hidden (404, no footer link) rather
    // than served with a placeholder. false = the older behaviour: answer 503.
    'hide_unfilled' => (bool) env('LEGAL_HIDE_UNFILLED', true),

    'company' => [
        'name' => 'JUWRI LTD',
        'number' => '14363043',
        'address' => '128 City Road, London, EC1V 2NX, United Kingdom',
        'email' => 'legal@juwri.com',
    ],

    /*
     * One entry per published document. `versions` = version => effective date (Y-m-d), oldest first; the
     * last entry whose date has come is the current version, a later one is an announced change (Terms
     * §14.2: at least 30 days' notice). `acceptance` = sign-up checkbox / re-acceptance banner (Terms §14.13).
     * A version's date may be null (J51e): announced in the text but not yet dated, so it is not in force and no
     * notice goes out; the owner sets the date (.env LEGAL_TERMS_1_1_EFFECTIVE, Y-m-d) when the change notice
     * is sent, at least 30 days ahead for a material change.
     * `label` = the site.php footer key. `non_material` (Terms only) = versions that are not a material change:
     * without it a new Terms version must take effect at least 30 days after its notice starts (Terms §14.2;
     * Juwri\Core\Legal\TermsChangeNotice e-mails every account owner once per version, daily schedule).
     */
    'documents' => [
        'terms' => ['path' => 'terms', 'label' => 'site.footer.terms', 'acceptance' => true, 'versions' => ['1.0' => '2026-10-04', '1.1' => env('LEGAL_TERMS_1_1_EFFECTIVE') ?: null], 'non_material' => []],
        'privacy' => ['path' => 'privacy', 'label' => 'site.footer.privacy', 'acceptance' => false, 'versions' => ['1.0' => '2026-10-04']],
        'dpa' => ['path' => 'legal/dpa', 'label' => 'site.footer.dpa', 'acceptance' => true, 'versions' => ['1.0' => '2026-10-04']],
        'aup' => ['path' => 'legal/aup', 'label' => 'site.footer.aup', 'acceptance' => true, 'versions' => ['1.0' => '2026-10-04']],
        'sla' => ['path' => 'legal/sla', 'label' => 'site.footer.sla', 'acceptance' => false, 'versions' => ['1.0' => '2026-10-04']],
        'subprocessors' => ['path' => 'legal/subprocessors', 'label' => 'site.footer.subprocessors', 'acceptance' => false, 'versions' => ['1.0' => '2026-10-04']],
        'security' => ['path' => 'security', 'label' => 'site.footer.security', 'acceptance' => false, 'versions' => ['1.0' => '2026-10-04']],
    ],

    /* Owner facts ({{FILL:key}}), publish/FILL-LIST.md. Empty = unresolved. */
    'fill' => [
        'pgp_fingerprint' => env('LEGAL_PGP_FINGERPRINT'),
        'github_url' => env('LEGAL_GITHUB_URL'),
        'hosting_provider' => env('LEGAL_HOSTING_PROVIDER'),
        'hosting_region' => env('LEGAL_HOSTING_REGION'),
        'hosting_certifications' => env('LEGAL_HOSTING_CERTIFICATIONS'),
        'egypt_representative' => env('LEGAL_EGYPT_REPRESENTATIVE'),
        'ico_number' => env('LEGAL_ICO_NUMBER'),
        'analytics_tool' => env('LEGAL_ANALYTICS_TOOL'),
        'higher_plans' => env('LEGAL_HIGHER_PLANS'),
        'whatsapp_number' => env('LEGAL_WHATSAPP_NUMBER'),
        'support_phone' => env('LEGAL_SUPPORT_PHONE'),
        'holiday_calendar_url' => env('LEGAL_HOLIDAY_CALENDAR_URL'),
        'backup_region' => env('LEGAL_BACKUP_REGION'),
        // Sub-processor list: vendor and processing location per row.
        'vendor_hosting' => env('LEGAL_VENDOR_HOSTING'),
        'vendor_backup' => env('LEGAL_VENDOR_BACKUP'),
        'vendor_cdn' => env('LEGAL_VENDOR_CDN'),
        'vendor_email' => env('LEGAL_VENDOR_EMAIL'),
        'vendor_messaging' => env('LEGAL_VENDOR_MESSAGING'),
        'vendor_ai' => env('LEGAL_VENDOR_AI'),
        'vendor_maps' => env('LEGAL_VENDOR_MAPS'),
        'vendor_monitoring' => env('LEGAL_VENDOR_MONITORING'),
        'vendor_support' => env('LEGAL_VENDOR_SUPPORT'),
        'vendor_analytics' => env('LEGAL_VENDOR_ANALYTICS'),
        'vendor_push' => env('LEGAL_VENDOR_PUSH'),
        'vendor_logging' => env('LEGAL_VENDOR_LOGGING'),
        'location_cdn' => env('LEGAL_LOCATION_CDN'),
        'location_email' => env('LEGAL_LOCATION_EMAIL'),
        'location_messaging' => env('LEGAL_LOCATION_MESSAGING'),
        'location_ai' => env('LEGAL_LOCATION_AI'),
        'location_maps' => env('LEGAL_LOCATION_MAPS'),
        'location_monitoring' => env('LEGAL_LOCATION_MONITORING'),
        'location_support' => env('LEGAL_LOCATION_SUPPORT'),
        'location_analytics' => env('LEGAL_LOCATION_ANALYTICS'),
        'location_push' => env('LEGAL_LOCATION_PUSH'),
        'location_logging' => env('LEGAL_LOCATION_LOGGING'),
    ],

    /* Product promises ({{VERIFY:key}}): true once confirmed true on launch day (else soften the text). */
    'verify' => [
        'ai_no_training' => (bool) env('LEGAL_VERIFY_AI_NO_TRAINING', false),
        'encryption_at_rest' => (bool) env('LEGAL_VERIFY_ENCRYPTION_AT_REST', false),
        'auth_methods' => (bool) env('LEGAL_VERIFY_AUTH_METHODS', false),
        'backups' => (bool) env('LEGAL_VERIFY_BACKUPS', false),
    ],

    /*
     * Sub-processors marked "Not used" (J51b): one entry per removable sub-processor key
     * (Juwri\Core\Legal\LegalSettings::SUBPROCESSORS; "hosting" is never removable). When
     * true, the matching row is dropped from the English and Arabic Sub-processors page and
     * its {{FILL:vendor_<x>}} / {{FILL:location_<x>}} / {{FILL:backup_region}} markers no
     * longer count as unresolved. Populated from the platform settings store at runtime
     * (LegalSettings::applyToConfig), not from the environment.
     */
    'unused' => [],

    /*
     * J71 (O1): the public address of the repository's GitHub private vulnerability reporting page (for example
     * https://github.com/<owner>/<repo>/security/advisories/new). Defaults to the public Community repository; set the env
     * to another https URL to override it, or to an empty value so the legal pages say "GitHub private vulnerability
     * reporting on our repository" and security.txt lists only the Security page.
     */
    'security_report_url' => env('LEGAL_SECURITY_REPORT_URL', 'https://github.com/kaderBK/Juwri/security/advisories/new'),

    /* /.well-known/security.txt (RFC 9116), from security.md §9. Optional lines are left out while empty. */
    'security_txt' => [
        'contact' => [], // the GitHub reporting URL is added from security_report_url (validated, see Legal\SecurityReport)
        'expires' => env('LEGAL_SECURITY_TXT_EXPIRES', '2027-10-01T00:00:00.000Z'),
        'encryption' => env('LEGAL_SECURITY_TXT_ENCRYPTION'), // e.g. https://juwri.com/.well-known/pgp-key.txt
        'acknowledgments' => env('LEGAL_SECURITY_TXT_ACKNOWLEDGMENTS'),
        'preferred_languages' => 'en, ar',
        // Armoured public key served at /.well-known/pgp-key.txt (absolute path or relative to storage/app).
        'pgp_key_path' => env('LEGAL_PGP_KEY_PATH'),
    ],

    // P6-86: delete newsletter subscribers 24 months after sign-up. Off by default: no open/click data exists, so the
    // join-date rule would also remove active readers. Store customer opt-in switch-off is not affected.
    'prune_subscribers' => (bool) env('MARKETING_PRUNE_SUBSCRIBERS', false),
];
