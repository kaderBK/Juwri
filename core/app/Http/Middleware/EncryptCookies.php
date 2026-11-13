<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http\Middleware;

use Illuminate\Cookie\Middleware\EncryptCookies as Middleware;

class EncryptCookies extends Middleware
{
    /**
     * The names of the cookies that should not be encrypted.
     *
     * @var array<int, string>
     */
    protected $except = [
        // Reseller v2: referral attribution code — non-sensitive, kept plain so the
        // signup flow (and any front-end read) sees the raw code.
        'reseller_ref',
        // Marketplace P0-4: browsing context {country_id, city_id} — non-sensitive
        // geo selection, kept plain so the picker JS can read/inspect it.
        'mk_ctx',
        // AL-11/41: the auto-locale banner writes these client-side (unencrypted),
        // and the login sync reads qc_lang_choice server-side — both must stay plain.
        'qc_lang_choice',
        'qc_lang_dismissed',
        // P1-23: the cookie banner's choice (written client-side, read by CookieConsent).
        'site_consent',
        // P3-06: the storefront language: written by the legacy themes' JS (plain) and, for `?lang=`, by the server; read raw ($_COOKIE).
        'Quick_user_lang_code',
    ];
}
