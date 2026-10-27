<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

use App\Models\Customer;
use App\Models\StaffMember;
use Juwri\Core\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | This option defines the default authentication "guard" and password
    | reset "broker" for your application. You may change these values
    | as required, but they're a perfect start for most applications.
    |
    */

    'defaults' => [
        'guard' => 'web',
        'passwords' => 'users',
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Guards
    |--------------------------------------------------------------------------
    |
    | Next, you may define every authentication guard for your application.
    | Of course, a great default configuration has been defined for you
    | which utilizes session storage plus the Eloquent user provider.
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | Supported: "session"
    |
    */

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],

        // Storefront customers.
        'customer' => [
            'driver' => 'session',
            'provider' => 'customers',
        ],

        // Restaurant staff (PIN sign-in).
        'staff' => [
            'driver' => 'session',
            'provider' => 'staff',
        ],

        // Third-party app tokens (Passport authorization_code grant).
        'api' => [
            'driver' => 'passport',
            'provider' => 'users',
        ],
        // The marketplace `shopper` guard + `shoppers` provider are added by the Marketplace module (P1-13, ee).
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | If you have multiple user tables or models you may configure multiple
    | providers to represent the model / table. These providers may then
    | be assigned to any extra authentication guards you have defined.
    |
    | Supported: "database", "eloquent"
    |
    */

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => User::class,
        ],

        'customers' => [
            'driver' => 'eloquent',
            'model' => Customer::class,
        ],

        'staff' => [
            'driver' => 'eloquent',
            'model' => StaffMember::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | These configuration options specify the behavior of Laravel's password
    | reset functionality, including the table utilized for token storage
    | and the user provider that is invoked to actually retrieve users.
    |
    | The expiry time is the number of minutes that each reset token will be
    | considered valid. This security feature keeps tokens short-lived so
    | they have less time to be guessed. You may change this as needed.
    |
    | The throttle setting is the number of seconds a user must wait before
    | generating more password reset tokens. This prevents the user from
    | quickly generating a very large amount of password reset tokens.
    |
    | The token table keeps its historical name until the Phase 1 schema.
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password Confirmation Timeout
    |--------------------------------------------------------------------------
    |
    | Here you may define the number of seconds before a password confirmation
    | window expires and users are asked to re-enter their password via the
    | confirmation screen. By default, the timeout lasts for three hours.
    |
    */

    'password_timeout' => 10800,

    /*
    |--------------------------------------------------------------------------
    | Token query-string fallback
    |--------------------------------------------------------------------------
    |
    | The token middlewares accept the bearer token from a ?…_token query
    | string as a fallback. That leaks long-lived tokens into access logs and
    | Referer headers, so production is header-only by default; local and
    | testing keep the deprecated fallback unless it is switched off.
    |
    */

    'token_query_fallback' => env(
        'TOKEN_QUERY_FALLBACK',
        env('APP_ENV', 'production') !== 'production'
    ),

    /*
    |--------------------------------------------------------------------------
    | Password policy and privileged 2FA
    |--------------------------------------------------------------------------
    |
    | password_policy_strict (off by default): Password::defaults() requires
    | at least 10 characters, mixed case, a number and a password that is not
    | in a known breach. Off keeps the historical minimum of 6 characters so
    | existing users are never locked out until the owner turns it on.
    |
    | force_2fa_admin (off by default): a superadmin or reseller-portal owner
    | without 2FA enrolled is sent to enrolment before reaching their panel.
    |
    */

    'password_policy_strict' => env('PASSWORD_POLICY_STRICT', false),

    'force_2fa_admin' => env('FORCE_2FA_ADMIN', false),

    /*
    | A1 (upstream superadmin hardening, 185d3adb0): superadmin panel sessions end
    | after admin_idle_timeout seconds without an admin request, and the
    | sensitive sections need the password re-entered within
    | admin_password_timeout seconds (admin_step_up = false turns that off).
    */
    'admin_idle_timeout' => (int) env('ADMIN_IDLE_TIMEOUT', 1800),
    'admin_password_timeout' => (int) env('ADMIN_PASSWORD_TIMEOUT', 1800),
    'admin_step_up' => (bool) env('ADMIN_STEP_UP', true),

];
