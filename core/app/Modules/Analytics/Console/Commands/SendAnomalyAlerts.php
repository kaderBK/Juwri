<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Analytics\Console\Commands;

use App\Models\StoreView;
use App\Support\StoreClock;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Juwri\Core\Models\User;
use Juwri\Core\Models\UserOption;
use Juwri\Core\Settings\UserSettings;
use Juwri\Modules\Analytics\Mail\DailyAnomalyAlertMail;

class SendAnomalyAlerts extends Command
{
    protected $signature = 'analytics:send-anomaly-alerts';

    protected $description = 'Send daily scan anomaly alerts to opted-in merchants';

    public function handle(): int
    {
        $this->info('Starting anomaly alert scan...');

        $today = Carbon::today();

        $userIds = UserSettings::userIdsWhere('anomaly_alerts_email', fn ($v) => $v === '1');

        $users = User::whereIn('id', $userIds)->get();

        foreach ($users as $user) {
            if (UserOption::getUserOption($user->id, 'last_scan_drop_alert_at') === $today->toDateString()) {
                continue;
            }

            $storeIds = $user->stores()->pluck('id')->toArray();
            if (empty($storeIds)) {
                continue;
            }

            // P6-250: YESTERDAY (a full day) against the 7 full days before it, each store on its own calendar day.
            // The run is at 09:00, so "today" is a partial day that always looked like a drop.
            $currentScans = 0;
            $baselineTotal = 0;
            foreach ($storeIds as $storeId) {
                [$y, $b] = $this->storeScans((int) $storeId);
                $currentScans += $y;
                $baselineTotal += $b;
            }

            $baselineAverageScans = round($baselineTotal / 7, 1);
            if ($baselineAverageScans < 5) {
                continue;
            }

            $dropPercent = round((($baselineAverageScans - $currentScans) / $baselineAverageScans) * 100, 1);
            if ($currentScans > ($baselineAverageScans * 0.6) || $dropPercent < 40) {
                continue;
            }

            $mailData = [
                'name' => $user->name ?: $user->username,
                'currentScans' => $currentScans,
                'baselineAverageScans' => $baselineAverageScans,
                'dropPercent' => $dropPercent,
                'restaurantCount' => count($storeIds),
                'comparisonWindow' => 7,
            ];

            // P6-250: one bad address must not stop the rest (the mailable is queued; this guards the enqueue itself).
            try {
                Mail::to($user->email)->send(new DailyAnomalyAlertMail($mailData));
                UserOption::updateUserOption($user->id, 'last_scan_drop_alert_at', $today->toDateString());
                $this->info("Queued anomaly alert for user: {$user->email}");
            } catch (\Throwable $e) {
                Log::warning('[anomaly-alerts] could not queue the mail', ['user' => $user->id, 'error' => $e->getMessage()]);
                $this->error("Could not queue anomaly alert for user {$user->id}: {$e->getMessage()}");
            }
        }

        $this->info('Anomaly alert scan complete!');

        return 0;
    }

    /**
     * Yesterday's scans and the total of the 7 full days before it, on the store's own calendar days.
     *
     * @return array{int, int}
     */
    private function storeScans(int $storeId): array
    {
        $yesterday = Carbon::parse(StoreClock::today($storeId), StoreClock::tz($storeId))->subDay()->toDateString();
        $first = Carbon::parse($yesterday)->subDays(7)->toDateString();
        [$start, $end] = StoreClock::rangeWindow($storeId, $first, $yesterday);

        $yesterdayCount = 0;
        $baseline = 0;
        foreach (StoreView::where('store_id', $storeId)->where('date', '>=', $start)->where('date', '<', $end)->get(['date']) as $view) {
            $day = StoreClock::localDate($storeId, $view->date);
            if ($day === $yesterday) {
                $yesterdayCount++;
            } elseif ($day !== null && $day >= $first) {
                $baseline++;
            }
        }

        return [$yesterdayCount, $baseline];
    }
}
