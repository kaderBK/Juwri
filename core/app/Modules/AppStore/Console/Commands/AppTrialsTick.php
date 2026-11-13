<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\AppStore\Console\Commands;

use Juwri\Modules\AppStore\Mail\AppTrialReminderMail;
use App\Models\AppInstallation;
use Juwri\Core\Models\User;
use App\Services\FeatureSpotlight;
use Illuminate\Console\Command;

/**
 * B4 (rev/upsell-visibility): app trial reminders.
 *
 * AppInstallation.trial_ends_at has been populated since P4 and shown nowhere,
 * so a trial could lapse without the merchant ever being told. This sends the
 * T-3, T-1 and expiry mails.
 *
 * Every stage is stamped on the install, so a scheduler that runs hourly — or
 * an operator running this by hand — cannot send the same reminder twice. The
 * stamp is written even when the merchant has unsubscribed, so unsubscribing
 * silences the mail rather than deferring it.
 */
class AppTrialsTick extends Command
{
    protected $signature = 'apps:trial-reminders {--dry-run : report what would be sent, send nothing}';

    protected $description = 'Send T-3 / T-1 / expiry reminders for app trials (idempotent per stage)';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $stats = ['considered' => 0, 'sent' => 0, 'skipped_sent' => 0, 'skipped_unsubscribed' => 0];

        AppInstallation::with(['app', 'user'])
            ->where('status', 'active')
            ->whereNotNull('trial_ends_at')
            // A trial that ended long ago is settled; only the recent tail matters.
            ->where('trial_ends_at', '>=', now()->subDays(3))
            ->orderBy('id')
            ->chunkById(200, function ($installs) use (&$stats, $dry) {
                foreach ($installs as $install) {
                    $stats['considered']++;
                    $stage = $this->stageFor($install);
                    if (! $stage) {
                        continue;
                    }

                    if ($install->trialReminderSent($stage)) {
                        $stats['skipped_sent']++;
                        continue;
                    }

                    $owner = $install->user;
                    if (! $owner || empty($owner->email)) {
                        continue;
                    }

                    if (! self::wantsTrialEmails($owner)) {
                        // Stamp anyway: unsubscribing silences it, it does not queue it up.
                        if (! $dry) {
                            $install->stampTrialReminder($stage);
                        }
                        $stats['skipped_unsubscribed']++;
                        continue;
                    }

                    if ($dry) {
                        $stats['sent']++;
                        continue;
                    }

                    if ($this->send($install, $owner, $stage)) {
                        $install->stampTrialReminder($stage);
                        $stats['sent']++;
                    }
                }
            });

        $this->info(json_encode($stats));

        return self::SUCCESS;
    }

    /** Which reminder, if any, is due for this install right now. */
    private function stageFor(AppInstallation $install): ?string
    {
        $left = $install->trialDaysLeft();

        return match (true) {
            $left === null => null,
            $left <= 0 => 'ended',
            $left === 1 => 't_minus_1',
            $left <= 3 => 't_minus_3',
            default => null,
        };
    }

    /** Owners can turn these off; the option is written by the signed link in the mail. */
    public static function wantsTrialEmails(User $owner): bool
    {
        try {
            return user_options($owner->id, 'spotlight_trial_emails') !== '0';
        } catch (\Throwable $e) {
            return true;
        }
    }

    private function send(AppInstallation $install, User $owner, string $stage): bool
    {
        try {
            $owner->sendMail(new AppTrialReminderMail(
                $owner->name ?: ___('there'),
                $install->app?->name ?: ___('your app'),
                $stage,
                (int) $install->trialDaysLeft(),
                FeatureSpotlight::appManageUrl($install),
                FeatureSpotlight::trialUnsubscribeUrl($owner),
            ));

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }
}
