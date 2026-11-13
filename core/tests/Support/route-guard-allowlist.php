<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

/**
 * P6-18 route guard sweep: justified exceptions for tests/Feature/RouteGuardSweepTest.php.
 * Key = "METHOD uri" (first non-HEAD method, uri as in route:list). Every entry needs a reason.
 * Adding a line here is a security decision: say WHY the route is safe without auth/throttle.
 */
return [
    'unguarded' => [
        'POST _boost/browser-logs' => 'Laravel Boost dev-only logger; registered only in local env',
        'POST logout' => 'ends the caller\'s own session; CSRF-protected; nothing to brute-force',
        'POST {store}/customer/logout' => 'ends the caller\'s own customer session; CSRF-protected',
        'POST {store}/customer/addresses' => 'controller customerForRestaurant() requires a customer session (redirects otherwise)',
        'PUT {store}/customer/addresses/{address}' => 'controller customerForRestaurant() requires a customer session; address scoped to customer',
        'DELETE {store}/customer/addresses/{address}' => 'controller customerForRestaurant() requires a customer session; address scoped to customer',
        'POST {store}/customer/profile' => 'controller customerForRestaurant() requires a customer session',
        'POST {store}/customer/social/unlink' => 'controller customerForRestaurant() requires a customer session',
        'POST {store}/customer/orders/{order}/reorder' => 'customer session required; order must belong to store + customer (abort 403)',
    ],
    'unthrottled_auth' => [
        'POST admin/abuse-guard/signup' => 'admin settings form (signup abuse config), not a signup endpoint; admin guard',
        'POST admin/users/{user}/edit/password' => 'admin sets a user password; admin guard + 2FA-forced admin session',
        'POST admin/users/{user}/login' => 'admin impersonation; admin guard; audited',
        'PATCH passkeys/{passkeyId}' => 'rename own passkey; authenticated owner session',
        'DELETE passkeys/{passkeyId}' => 'delete own passkey; authenticated owner session',
        'DELETE staff/passkeys/{passkeyId}' => 'delete own staff passkey; authenticated staff session',
        'DELETE staff/{id}/passkeys/{passkeyId}' => 'owner revokes a staff passkey; authenticated + store scoped',
        'POST two-factor/enable' => 'authenticated + password.confirm (re-auth is the brute-force gate)',
        'POST two-factor/recovery-codes' => 'authenticated + password.confirm',
    ],
];
