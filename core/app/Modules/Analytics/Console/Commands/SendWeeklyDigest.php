<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Analytics\Console\Commands;

use App\Models\Order;
use App\Models\StoreView;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\UserSettings;
use Juwri\Modules\Analytics\Mail\WeeklyDigestMail;
use Throwable;

class SendWeeklyDigest extends Command
{
    protected $signature = 'analytics:send-weekly-digest';

    protected $description = 'Send weekly analytics digest to opted-in merchants';

    public function handle()
    {
        $this->info('Starting weekly analytics digest...');

        $userIds = UserSettings::userIdsWhere('weekly_digest_email', fn ($v) => $v === '1');
        $users = User::whereIn('id', $userIds)->get();

        $startDate = Carbon::now()->subWeek()->startOfWeek();
        $endDate = Carbon::now()->subWeek()->endOfWeek();

        $queued = 0;
        $failed = 0;

        foreach ($users as $user) {
            try {
                $storeIds = $user->stores()->pluck('id')->toArray();
                if (empty($storeIds)) {
                    continue;
                }

                $totalScans = StoreView::whereIn('store_id', $storeIds)
                    ->whereBetween('date', [$startDate, $endDate])
                    ->count();

                $uniqueVisitors = StoreView::whereIn('store_id', $storeIds)
                    ->whereBetween('date', [$startDate, $endDate])
                    ->distinct('ip')
                    ->count('ip');

                $orders = Order::whereIn('store_id', $storeIds)
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->where(function ($q) {
                        $q->where('status', 'completed')->orWhere('is_paid', 1);
                    })
                    ->get();

                $totalRevenue = $orders->sum('total');
                $ordersCount = $orders->count();
                $conversionRate = $totalScans > 0 ? round(($ordersCount / $totalScans) * 100, 1) : 0;
                // S7: email clients block webfonts, so an undrawable sign (⃁, ﷼, ₹,
                // د.إ) would tofu — fall back to the ISO code for the digest.
                $currencyCode = store_options($storeIds[0], 'currency_code') ?: null;
                $currencySymbol = currency_sign_safe(store_options($storeIds[0], 'currency_sign', '$'), (string) $currencyCode);
                // Same store as the symbol, so the digest agrees with the analytics
                // screen and its CSV rather than rounding a 3-decimal revenue to 2.
                $currencyDecimals = currency_decimals($currencyCode);

                $mailData = [
                    'name' => $user->name ?: $user->username,
                    'totalScans' => $totalScans,
                    'uniqueVisitors' => $uniqueVisitors,
                    'totalRevenue' => $totalRevenue,
                    'ordersCount' => $ordersCount,
                    'conversionRate' => $conversionRate,
                    'currencySymbol' => $currencySymbol,
                    'currencyDecimals' => $currencyDecimals,
                ];

                Mail::to($user->email)->queue(new WeeklyDigestMail($mailData));
                $queued++;
            } catch (Throwable $e) {
                // One bad recipient must not abort the run for the rest — report and keep going.
                report($e);
                $failed++;
                $this->warn("Failed weekly digest for user {$user->email}: {$e->getMessage()}");

                continue;
            }
        }

        $this->info("Weekly analytics digest complete: {$queued} queued, {$failed} failed.");

        return 0;
    }
}
