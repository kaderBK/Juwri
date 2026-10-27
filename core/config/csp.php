<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use App\Support\Csp\StorefrontPolicy;
use Juwri\Core\Support\Csp\RandomNonce;

return [

    /*
     * Exposed separately so storefront integrations can fail closed when the
     * enforcing policy is active. Keep this env read inside config so it remains
     * compatible with config:cache.
     */
    'enforce' => env('CSP_ENFORCE', true),

    /*
     * A policy will determine which CSP headers will be set. A valid CSP policy is
     * any class that extends `Juwri\Core\Support\Csp\Policy`
     */
    /*
     * P4-17 follow-up (2026-10-07): ENFORCED by default on every storefront page (storefront_enforce=all) and on the
     * dashboard (enforce_dashboard) — the ZAP baseline flagged report-only as a Medium. The env switches stay as an
     * emergency roll-back (CSP_ENFORCE=false, CSP_STOREFRONT_ENFORCE=store_hosts, CSP_ENFORCE_DASHBOARD=false).
     *
     * P0-4: the storefront policy is applied ENFORCING only when CSP_ENFORCE=true.
     * Until then it rides as report-only so violations can be collected during the
     * owner's browser pass without breaking any menu. Scoped to storefront routes
     * via the AddCspHeaders route middleware — admin/dashboard are untouched.
     */
    'policy' => env('CSP_ENFORCE', true) ? StorefrontPolicy::class : '',

    /*
     * This policy which will be put in report only mode. This is great for testing out
     * a new policy or changes to existing csp policy without breaking anything.
     */
    'report_only_policy' => env('CSP_ENFORCE', true) ? '' : StorefrontPolicy::class,

    /*
     * S12: independent enforce switch for the PRIVILEGED-surface policy
     * (DashboardPolicy on admin/dashboard/reseller/staff-PWA, applied by the
     * `csp.dashboard` middleware). Default off → those surfaces ride report-only
     * so nothing breaks; flip to true only after the dashboard inline scripts
     * are nonced. Kept separate from CSP_ENFORCE so enforcing the (nonced)
     * storefront never enforces the not-yet-nonced dashboard.
     */
    'enforce_dashboard' => env('CSP_ENFORCE_DASHBOARD', true),

    /*
     * P1-20 (J28): where the storefront policy is ENFORCED regardless of CSP_ENFORCE —
     * `store_hosts` (default): verified custom domains + platform subdomains, and the storefront
     * host in HOST_MODE=split; `all`: every storefront page; `off`: pre-P1-20 (CSP_ENFORCE only).
     */
    'storefront_enforce' => env('CSP_STOREFRONT_ENFORCE', 'all'),

    /*
     * A2 (upstream 855066828): enforce the storefront policy
     * theme by theme before the global CSP_ENFORCE flip — a comma list of
     * store_template keys (e.g. "crumb,velora"). Other themes stay
     * report-only. Verify each theme in a browser before adding it.
     */
    'enforce_templates' => array_values(array_filter(array_map('trim', explode(',', (string) env('CSP_ENFORCE_TEMPLATES', ''))))),

    /*
     * All violations against the policy will be reported to this url.
     * A great service you could use for this is https://report-uri.com/
     *
     * You can override this setting by calling `reportTo` on your policy.
     */
    'report_uri' => env('CSP_REPORT_URI', '/csp-report'),

    /*
     * Headers will only be added if this setting is set to true.
     */
    'enabled' => env('CSP_ENABLED', true),

    /*
     * The class responsible for generating the nonces used in inline tags and headers.
     */
    'nonce_generator' => RandomNonce::class,
];
