<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * Plain-text notice shown while the app is not set up yet (no application key
 * or an unmigrated database). Once setup is done it forwards to the home page.
 */
class SetupPendingController extends Controller
{
    public function __invoke(): Response|RedirectResponse
    {
        if (app_is_installed()) {
            return redirect()->route('home');
        }

        return response(
            "This site is not set up yet.\n\n"
            ."1. Copy core/.env.example to core/.env and fill in the database settings.\n"
            ."2. php artisan key:generate\n"
            ."3. php artisan migrate --seed\n"
            ."4. php artisan storage:link (on shared hosting without symlink support: copy core/storage/app/public to public/storage)\n"
            ."5. Sign in as the admin and open Settings > Legal & compliance (.../settings/legal under your admin URL) to fill the legal-page facts.\n",
            503,
            ['Content-Type' => 'text/plain; charset=UTF-8', 'Retry-After' => '60']
        );
    }
}
