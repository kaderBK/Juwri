<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * P1-00a: CSP nonce helper (was provided by spatie/laravel-csp 2.x; J18 removes
 * spatie/*). Every inline <script>/<style> in the storefront and dashboard
 * carries nonce="{{ csp_nonce() }}". Bound in Juwri\Core\Providers\CspServiceProvider.
 */

if (! function_exists('csp_nonce')) {
    function csp_nonce(): string
    {
        return (string) app('csp-nonce');
    }
}
