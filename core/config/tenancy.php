<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/*
 * P1-04 tenancy (J27-A1, J29). Account → Store → Branch.
 */
return [
    // Data-residency regions an account may be pinned to. A Cloud region is its own deployment
    // (no cross-region reads); self-hosted installs normally stay on 'global'.
    'regions' => array_values(array_filter(array_map('trim', explode(',', (string) env('TENANCY_REGIONS', 'global,sa,ae'))))),

    // Region new accounts get unless the sign-up flow picks another one.
    'default_region' => env('TENANCY_DEFAULT_REGION', 'global'),

    // P1-04 slice 6: PostgreSQL row-level security on account_id (J29-2; no-op on MySQL/SQLite). Policies come
    // from TenantTables; the app keeps app.account_id / app.tenant_bypass in line with the tenant context before
    // every query. It only restricts a NON-owner, non-superuser database role: connect the app with such a role
    // in Cloud (docs/dev/tenancy-isolation.md). app_role = optional role that `tenancy:rls sync` grants DML to.
    // P1-04 slice 7 (J27-A1): region => storage disk for tenant files (TenantStorage::disk); empty = the
    // caller's disk (single-region installs). A Cloud region is its own deployment.
    'storage' => [
        'region_disks' => array_filter(json_decode((string) env('TENANCY_REGION_DISKS', '{}'), true) ?: []),
    ],

    // P1-04 slice 7: session keys that belong to one account (dropped when the session's account changes).
    'session' => [
        'tenant_keys' => ['dashboard_store_id', 'dashboard_branch_id', 'square_oauth', 'gbp_oauth', 'foodics_oauth'],
    ],

    // P1-04 slice 7: account-wide request budget on the owner token API, shared by all of an account's tokens
    // (on top of the per-token owner-api limit).
    'rate_limits' => [
        'owner_api_per_account' => (int) env('TENANCY_OWNER_API_ACCOUNT_RATE', 600),
        'storefront_write_per_account' => (int) env('TENANCY_STOREFRONT_WRITE_ACCOUNT_RATE', 300),
    ],

    'rls' => [
        'enabled' => (bool) env('TENANCY_RLS', true),
        'app_role' => env('TENANCY_RLS_APP_ROLE', ''),
    ],
];
