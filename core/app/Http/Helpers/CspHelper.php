<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use Juwri\Core\Support\Pdf\PdfDocument;

/*
 * P1-00a: CSP nonce helper (was provided by spatie/laravel-csp 2.x; J18 removes
 * spatie/*). Every inline <script>/<style> in the storefront and dashboard
 * carries nonce="{{ csp_nonce() }}". Bound in Juwri\Core\Providers\CspServiceProvider.
 */

/*
 * Not CSP: this file is one of composer's autoload "files", so it runs before any request, test or class scan can
 * autoload TCPDF. TCPDF's own autoconfig defines K_TCPDF_THROW_EXCEPTION_ERROR=false when it loads first, and a later
 * define() is ignored (see PdfDocument::defineEngineConstants).
 */
PdfDocument::defineEngineConstants();

if (! function_exists('csp_nonce')) {
    function csp_nonce(): string
    {
        return (string) app('csp-nonce');
    }
}
