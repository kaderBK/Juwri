<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Database\Seeders;

use Juwri\Core\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * H14: create the superadmin the nightly browser e2e logs in as.
 *
 * role-pages-smoke.js only pushes the `admin` role when E2E_ADMIN_EMAIL and
 * E2E_ADMIN_PASS are both set, and NO seeder in this repo creates a
 * user_type = 'admin' user — so the admin matrix (which is where the
 * driver-forked /admin/users list lives) has never been smoked.
 *
 * Two hard guards, on purpose: this seeder REFUSES to run in production, and it
 * has NO default password. A demo seeder that mints a superadmin with a known
 * credential would be a backdoor; this one cannot run without the operator
 * handing it a throwaway password for a throwaway database.
 */
class E2eAdminSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new \RuntimeException('E2eAdminSeeder must never run in production.');
        }

        $email = (string) env('E2E_ADMIN_EMAIL', '');
        $password = (string) env('E2E_ADMIN_PASS', '');

        if ($email === '' || $password === '') {
            throw new \RuntimeException('E2eAdminSeeder requires E2E_ADMIN_EMAIL and E2E_ADMIN_PASS.');
        }

        User::updateOrCreate(
            ['email' => $email],
            [
                'username' => 'e2eadmin',
                'name' => 'E2E Admin',
                'password' => Hash::make($password),
                'user_type' => 'admin',   // Juwri\Core\Models\User::isAdmin() :214
                'group_id' => '1',
                'status' => 1,
                'email_verified_at' => now(),
            ]
        );
    }
}
