<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Console\Commands;

use App\Http\Controllers\Admin\UserController;
use Illuminate\Console\Command;

class GenerateUserHungerinoExportPackage extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'users:generate-hungerino-export-package {token}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a Hungerino export package for users in the background';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $token = (string) $this->argument('token');

        try {
            app(UserController::class)->generateBulkHungerinoExportPackage($token);
            $this->info('Hungerino export package generated successfully.');
            return 0;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return 1;
        }
    }
}
