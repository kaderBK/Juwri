<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\AppStore\Console\Commands;

use Juwri\Modules\AppStore\Mail\AppDunningMail;
use App\Models\AppInstallation;
use Juwri\Core\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Polish (feature-platform, closes the P4-4 deferral): daily trial-ending
 * reminders at T-3 and T-1. Runs once a day, and each installation matches a
 * given day-mark exactly once, so no dedupe state is needed.
 */
class AppDunning extends Command
{
    protected $signature = 'apps:dunning';

    protected $description = 'Send T-3/T-1 app trial-ending reminder mails';

    public function handle(): int
    {
        $sent = 0;

        foreach ([3, 1] as $daysLeft) {
            $due = AppInstallation::with('app')
                ->where('status', 'active')
                ->whereDate('trial_ends_at', now()->addDays($daysLeft)->toDateString())
                ->get();

            foreach ($due as $installation) {
                $app = $installation->app;
                $user = User::find($installation->user_id);
                if (! $app || ! $user || empty($user->email) || $app->price <= 0) {
                    continue;
                }

                try {
                    Mail::to($user->email)->queue(new AppDunningMail(
                        ___('Your :app trial ends in :days day(s)', ['app' => $app->name, 'days' => $daysLeft]),
                        [
                            ___('Hi :name,', ['name' => $user->name]),
                            ___('Your free trial of :app ends on :date.', ['app' => $app->name, 'date' => $installation->trial_ends_at->format('Y-m-d')]),
                            ___('The first period (:price) will be charged to your wallet automatically. Top up your wallet to keep the app running, or uninstall it before the trial ends to avoid the charge.', ['price' => price_format($app->price)]),
                        ],
                    ));
                    $sent++;
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        }

        $this->info("{$sent} reminder(s) queued.");

        return self::SUCCESS;
    }
}
