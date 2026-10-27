<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Campaign;
use App\Models\Driver;
use App\Models\Ingredient;
use App\Models\KitchenStation;
use App\Models\LoyaltyProgram;
use App\Models\Order;
use App\Models\ReferralConversion;
use App\Models\Reservation;
use App\Models\StaffMember;
use App\Models\StoreView;
use App\Models\ThemeVersion;
use App\Models\WebhookEndpoint;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Contracts\Billing\PlanCatalog;
use Juwri\Core\Contracts\Billing\Subscriptions;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Money\Currencies;
use Juwri\Core\Money\Money;
use Juwri\Core\Services\ExchangeRateService;
use Juwri\Core\Support\Hooks;
use Juwri\Core\Support\LocaleCache;
use Juwri\Modules\Ai\Models\AiUsage;
use Juwri\Modules\Channels\Models\HsEventLog;
use Juwri\Modules\Integrations\Models\ApiToken;
use Juwri\Modules\Integrations\Models\WebhookDelivery;
use Juwri\Modules\Marketing\Models\AbExperiment;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Messaging\Models\WhatsAppUsage;
use Juwri\Modules\Payments\Models\Transaction;

/**
 * Aggregates the platform-wide numbers behind the superadmin dashboard.
 * Everything is cached: the page itself must never fan out 40 queries
 * on each load. Windows are rolling (last 30 days vs the 30 before)
 * rather than calendar months so deltas stay meaningful on the 1st.
 */
class AdminDashboardService
{
    /** Hook slot: the number of platform announcements live today (ee Platform module), or no answer. */
    public const ANNOUNCEMENTS_LIVE_SLOT = 'platform.announcements.live-count';

    /** P1-13 13f: hook slot the Reseller module (ee) fills with the number of active resellers (ops tile). */
    public const RESELLERS_ACTIVE_SLOT = 'reseller.admin.active-count';

    public const CACHE_TTL = 300;

    /** Bust every cached section (used by tests; safe to call anywhere). */
    public static function flush(): void
    {
        LocaleCache::forget('admin.dash.overview.v1');
        foreach ([7, 30, 90] as $days) {
            LocaleCache::forget('admin.dash.charts.v3.'.$days);
        }
    }

    public function overview(): array
    {
        return Cache::remember(LocaleCache::key('admin.dash.overview.v1'), self::CACHE_TTL, function () {
            return [
                'pulse' => $this->pulse(),
                'alerts' => $this->alerts(),
                'byPlan' => $this->subscribersByPlan(),
                'topCustomers' => $this->topCustomers(),
                'funnel' => $this->activationFunnel(),
                'planMix' => $this->planMix(),
                'adoption' => $this->featureAdoption(),
                'ops' => $this->operations(),
                'pendingReferrals' => (int) ReferralConversion::where('status', 'pending')->count(),
            ];
        });
    }

    /** Range-scoped datasets for the revenue + growth charts. */
    public function charts(int $days): array
    {
        $days = in_array($days, [7, 30, 90], true) ? $days : 30;

        return Cache::remember(LocaleCache::key('admin.dash.charts.v3.'.$days), self::CACHE_TTL, function () use ($days) {
            $from = Carbon::today()->subDays($days - 1);

            // M10: split revenue by transaction_method, not product_id. Add-ons and
            // store purchases all write product_id=0, so the old split mislabeled
            // every add-on/bundle sale as "wallet".
            // MC-42: confine each series to the platform base currency (base-OR-null,
            // matching pulse()'s MC-03 rule) so a multi-currency install never
            // blind-sums foreign amounts into one base-symbol line. Legacy rows
            // predate stamping (null) but are base-currency → single-currency
            // installs stay byte-identical. Foreign revenue surfaces separately in
            // 'totals_by_currency' below (chips; no converted total in v1).
            $base = platform_currency();
            $baseScope = fn ($q) => $base
                ? $q->where(fn ($w) => $w->whereNull('currency_code')->orWhere('currency_code', $base))
                : $q;
            $seriesFor = fn (array $methods) => $this->dailySeries(
                $baseScope(Transaction::where('status', Transaction::STATUS_SUCCESS)
                    ->whereIn('transaction_method', $methods)
                    ->where('created_at', '>=', $from))
                    ->selectRaw('DATE(created_at) as d, SUM('.Transaction::minorMoneySql('amount').') as v')
                    ->groupBy('d')->pluck('v', 'd'),
                $from, $days
            );

            // MC-42: platform-billing revenue booked in a NON-base currency over the
            // window, grouped by ISO code (highest first) for the per-currency chips.
            // Empty on every single-currency install — the strip stays hidden.
            $billingMethods = ['membership', 'addon', 'app_purchase', 'credit_pack', 'wallet_topup', 'gift_card'];
            $totalsByCurrency = [];
            if ($base) {
                $foreign = Transaction::where('status', Transaction::STATUS_SUCCESS)
                    ->whereIn('transaction_method', $billingMethods)
                    ->where('created_at', '>=', $from)
                    ->whereNotNull('currency_code')
                    ->where('currency_code', '!=', $base)
                    ->selectRaw('currency_code, SUM('.Transaction::minorMoneySql('amount').') as v')
                    ->groupBy('currency_code')->pluck('v', 'currency_code');
                foreach ($foreign as $code => $v) {
                    $totalsByCurrency[strtoupper((string) $code)] = Money::round((float) $v, Currencies::exponent((string) $code)); // P1-05 10b: exact sum, own decimals
                }
                arsort($totalsByCurrency);
            }

            $subscriptions = $seriesFor(['membership']);
            $addon = $seriesFor(['addon', 'app_purchase', 'credit_pack']); // credit packs count with add-ons
            $wallet = $seriesFor(['wallet_topup', 'gift_card']);

            // M10: net out refund reversals against the bucket they came from
            // (details.refunded_method), so a refunded sale doesn't inflate revenue.
            $bucketFor = ['membership' => 'sub', 'addon' => 'add', 'app_purchase' => 'add'];
            $refundAdj = ['sub' => [], 'add' => [], 'wal' => []];
            Transaction::where('transaction_method', 'refund')
                ->where('created_at', '>=', $from)
                ->get(['amount_minor', 'currency_code', 'details', 'created_at'])
                ->each(function ($r) use (&$refundAdj, $bucketFor) {
                    $method = (string) data_get($r->details, 'refunded_method', 'addon');
                    $bucket = $bucketFor[$method] ?? 'wal';
                    $d = $r->created_at->format('Y-m-d');
                    $refundAdj[$bucket][$d] = ($refundAdj[$bucket][$d] ?? 0) + (float) $r->amount; // amount is negative
                });
            $applyRefunds = function (array $series, array $adj) use ($from, $days) {
                for ($i = 0; $i < $days; $i++) {
                    $key = $from->copy()->addDays($i)->format('Y-m-d');
                    if (isset($adj[$key])) {
                        $series[$i] += $adj[$key];
                    }
                }

                return $series;
            };
            $subscriptions = $applyRefunds($subscriptions, $refundAdj['sub']);
            $addon = $applyRefunds($addon, $refundAdj['add']);
            $wallet = $applyRefunds($wallet, $refundAdj['wal']);

            $labels = [];
            for ($i = 0; $i < $days; $i++) {
                $labels[] = date_formating($from->copy()->addDays($i)->format('Y-m-d'), 'd M');
            }

            return [
                'labels' => $labels,
                'subscriptions' => array_map(fn ($v) => Money::round((float) $v, 2), $subscriptions),
                'addon' => array_map(fn ($v) => Money::round((float) $v, 2), $addon),
                'wallet' => array_map(fn ($v) => Money::round((float) $v, 2), $wallet),
                'totals' => [
                    'subscriptions' => Money::round(array_sum($subscriptions), 2),
                    'addon' => Money::round(array_sum($addon), 2),
                    'wallet' => Money::round(array_sum($wallet), 2),
                ],
                // MC-42: non-base-currency billing revenue as [ISO => amount] chips.
                'totals_by_currency' => $totalsByCurrency,
            ];
        });
    }

    /* ------------------------------------------------------------------ */

    private function pulse(): array
    {
        $now = Carbon::now();
        $cur = $now->copy()->subDays(30);
        $prev = $now->copy()->subDays(60);
        $sparkFrom = Carbon::today()->subDays(29);

        // MC-03: platform revenue only. Exclude marketplace shopper top-ups
        // (`mk_wallet_topup`), customer `order` payments and `refund` reversals so
        // pulse agrees with charts() — but keep null-method rows (legacy platform
        // billing predates method stamping). Confine to the platform base currency
        // so a multi-currency install never blind-sums foreign amounts into one
        // base-symbol figure; legacy rows predate currency stamping (null) but are
        // base-currency, so base-OR-null keeps single-currency installs
        // byte-identical.
        $excludedMethods = ['mk_wallet_topup', 'order', 'refund'];
        $base = platform_currency();
        $platformRevenue = fn () => Transaction::where('status', Transaction::STATUS_SUCCESS)
            ->where(fn ($q) => $q->whereNull('transaction_method')->orWhereNotIn('transaction_method', $excludedMethods))
            ->when($base, fn ($q) => $q->where(fn ($w) => $w->whereNull('currency_code')->orWhere('currency_code', $base)));

        $revenueCur = (float) $platformRevenue()->where('created_at', '>=', $cur)->sum(DB::raw(Transaction::minorMoneySql('amount')));
        $revenuePrev = (float) $platformRevenue()->whereBetween('created_at', [$prev, $cur])->sum(DB::raw(Transaction::minorMoneySql('amount')));

        /* F5-5: developer sandbox stores are seeded with fake menus/orders for
           app testing — they must never inflate the platform's real numbers. */
        $realOrders = fn () => SandboxStores::exclude(Order::query(), 'store_id');
        $realScans = fn () => SandboxStores::exclude(StoreView::query(), 'store_id');
        $realPosts = fn () => Store::excludingSandbox();

        $ordersCur = $realOrders()->where('created_at', '>=', $cur)->count();
        $ordersPrev = $realOrders()->whereBetween('created_at', [$prev, $cur])->count();

        $scansCur = $realScans()->where('date', '>=', $cur)->count();
        $scansPrev = $realScans()->whereBetween('date', [$prev, $cur])->count();

        $signupsCur = User::where('user_type', 'user')->where('created_at', '>=', $cur)->count();
        $signupsPrev = User::where('user_type', 'user')->whereBetween('created_at', [$prev, $cur])->count();

        $postsCur = $realPosts()->where('created_at', '>=', $cur)->count();
        $postsPrev = $realPosts()->whereBetween('created_at', [$prev, $cur])->count();

        /* R4 (plans rebuild): paid = a LIVE paid subscription row. Legacy counted
           group_id != free/trial, which silently included users whose plan had
           already expired — the dashboard number is truthful now. */
        $paidUsers = fn () => app(Subscriptions::class)->whereLivePaid(User::where('user_type', 'user'));
        $paidSubs = $paidUsers()->where('status', 1)->count();
        $newPaidCur = $paidUsers()->where('created_at', '>=', $cur)->count();
        $newPaidPrev = $paidUsers()->whereBetween('created_at', [$prev, $cur])->count();

        return [
            'revenue' => [
                'raw' => $revenueCur,
                'value' => price_symbol_format($revenueCur),
                'delta' => $this->delta($revenueCur, $revenuePrev),
                'spark' => $this->sparkline(
                    Transaction::where('status', Transaction::STATUS_SUCCESS)
                        ->where('created_at', '>=', $sparkFrom)
                        ->selectRaw('DATE(created_at) as d, SUM('.Transaction::minorMoneySql('amount').') as v')
                        ->groupBy('d')->pluck('v', 'd'), $sparkFrom
                ),
            ],
            'subs' => [
                'raw' => $paidSubs,
                'value' => format_number_count($paidSubs),
                'delta' => $this->delta($newPaidCur, $newPaidPrev),
                'spark' => null,
            ],
            'stores' => [
                'raw' => $realPosts()->count(),
                'value' => format_number_count($realPosts()->count()),
                'delta' => $this->delta($postsCur, $postsPrev),
                'spark' => null,
            ],
            'orders' => [
                'raw' => $ordersCur,
                'value' => format_number_count($ordersCur),
                'delta' => $this->delta($ordersCur, $ordersPrev),
                'spark' => $this->sparkline(
                    $realOrders()->where('created_at', '>=', $sparkFrom)
                        ->selectRaw('DATE(created_at) as d, COUNT(*) as v')
                        ->groupBy('d')->pluck('v', 'd'), $sparkFrom
                ),
            ],
            'scans' => [
                'raw' => $scansCur,
                'value' => format_number_count($scansCur),
                'delta' => $this->delta($scansCur, $scansPrev),
                'spark' => $this->sparkline(
                    $realScans()->where('date', '>=', $sparkFrom)
                        ->selectRaw('DATE(date) as d, COUNT(*) as v')
                        ->groupBy('d')->pluck('v', 'd'), $sparkFrom
                ),
            ],
            'signups' => [
                'raw' => $signupsCur,
                'value' => format_number_count($signupsCur),
                'delta' => $this->delta($signupsCur, $signupsPrev),
                'spark' => $this->sparkline(
                    User::where('user_type', 'user')
                        ->where('created_at', '>=', $sparkFrom)
                        ->selectRaw('DATE(created_at) as d, COUNT(*) as v')
                        ->groupBy('d')->pluck('v', 'd'), $sparkFrom
                ),
            ],
        ];
    }

    /** Rows only appear when the count is non-zero; the view shows an all-clear otherwise. */
    private function alerts(): array
    {
        $rows = [
            [
                'level' => 'danger',
                'label' => ___('Failed transactions (7 days)'),
                'count' => Transaction::where('status', 'failed')->where('created_at', '>=', Carbon::now()->subDays(7))->count(),
                'route' => route('admin.transactions.index'),
            ],
            [
                /* R4 (plans rebuild): the alert the legacy schema couldn't express —
                   live paid subscriptions ending within 7 days. */
                'level' => 'warning',
                'label' => ___('Paid subscriptions expiring within 7 days'),
                'count' => app(Subscriptions::class)->paidEndingBetween(Carbon::now(), Carbon::now()->addDays(7)),
                'route' => route('admin.users.index', ['plan_status' => 'active']),
            ],
            [
                'level' => 'danger',
                'label' => ___('Webhook endpoints auto-disabled after repeated failures'),
                'count' => WebhookEndpoint::where('active', false)->whereNotNull('disabled_reason')->count(),
                'route' => null,
            ],
            [
                'level' => 'warning',
                'label' => ___('Referral conversions awaiting review'),
                'count' => ReferralConversion::where('status', 'pending')->count(),
                'route' => route('admin.referrals.index'),
            ],
            [
                // B1 (P4-4 follow-up): marketplace theme versions awaiting a human decision.
                'level' => 'warning',
                'label' => ___('Theme versions pending review'),
                'count' => ThemeVersion::where('status', 'review')->count(),
                'route' => route('admin.theme-reviews.index'),
            ],
            [
                'level' => 'warning',
                'label' => ___('HungerStation sync errors (24 hours)'),
                'count' => HsEventLog::where('ok', false)->where('created_at', '>=', Carbon::now()->subDay())->count(),
                'route' => null,
            ],
            [
                'level' => 'warning',
                'label' => ___('Campaigns that failed to send (7 days)'),
                'count' => Campaign::where('status', 'failed')->where('updated_at', '>=', Carbon::now()->subDays(7))->count(),
                'route' => null,
            ],
        ];

        return array_values(array_filter($rows, fn ($r) => $r['count'] > 0));
    }

    private function subscribersByPlan(): array
    {
        /* R4: live paid subscriptions grouped by plan (admins excluded via user join). */
        $counts = app(Subscriptions::class)->livePaidCountsByPlan();

        $plans = $this->planNames(); // the name accessor localises
        $max = max(1, (int) $counts->max());

        return $counts->map(fn ($c, $pid) => [
            'name' => $plans[(int) $pid] ?? (___('Plan').' #'.$pid),
            'count' => (int) $c,
            'pct' => (int) round($c / $max * 100),
        ])->sortByDesc('count')->values()->all();
    }

    /**
     * Plan id => localised plan name (Billing's plans; none in Community).
     *
     * @return \Illuminate\Support\Collection<int|string, mixed>
     */
    private function planNames(): Collection
    {
        return app(PlanCatalog::class)->plans(false, ['id', 'name', 'translations'])->mapWithKeys(fn ($plan) => [$plan->getKey() => $plan->getAttribute('name')]);
    }

    private function topCustomers(): array
    {
        return Transaction::where('status', Transaction::STATUS_SUCCESS)
            ->where('created_at', '>=', Carbon::now()->subDays(30))
            ->whereNotNull('user_id')
            ->selectRaw('user_id, SUM('.Transaction::minorMoneySql('amount').') as total, COUNT(*) as n')
            ->groupBy('user_id')
            ->orderByDesc('total')
            ->limit(5)
            ->with('user:id,name,email')
            ->get()
            ->filter(fn ($r) => $r->user !== null)
            ->map(fn ($r) => [
                'id' => $r->user_id,
                'name' => $r->user->name,
                'email' => $r->user->email,
                'amount' => price_symbol_format((float) $r->total),
                'n' => (int) $r->n,
            ])->values()->all();
    }

    /** Restaurant activation: created → has menu → scanned → takes orders. */
    private function activationFunnel(): array
    {
        // F5-5: sandbox stores are seeded with a menu and are excluded everywhere here.
        $realPostCount = Store::excludingSandbox()->count();
        $total = max(1, $realPostCount);
        $withMenu = SandboxStores::exclude(Menu::query(), 'store_id')->distinct('store_id')->count('store_id');
        $withScans = SandboxStores::exclude(StoreView::query(), 'store_id')->distinct('store_id')->count('store_id');
        $withOrders = SandboxStores::exclude(Order::query(), 'store_id')->distinct('store_id')->count('store_id');

        $stage = fn (string $label, int $count) => [
            'label' => $label,
            'count' => $count,
            'pct' => (int) round($count / $total * 100),
        ];

        return [
            $stage(___('Restaurants created'), $realPostCount),
            $stage(___('Added a menu'), $withMenu),
            $stage(___('Got scanned'), $withScans),
            $stage(___('Received an order'), $withOrders),
        ];
    }

    private function planMix(): array
    {
        /* R4: mix from live subscription state — free = no live row, trial =
           trialing, else live plan. (Legacy mixed in long-expired group ids.) */
        $totalUsers = User::where('user_type', 'user')->count();
        $trialing = app(Subscriptions::class)->whereTrialing(User::where('user_type', 'user'))->count();

        $paidCounts = app(Subscriptions::class)->livePaidCountsByPlan();

        $planNames = $this->planNames(); // the name accessor localises
        $rows = collect([
            ['label' => ___('Free'), 'count' => max(0, $totalUsers - $trialing - (int) $paidCounts->sum())],
            ['label' => ___('Trial'), 'count' => $trialing],
        ]);
        foreach ($paidCounts as $pid => $c) {
            $rows->push(['label' => $planNames[(int) $pid] ?? (___('Plan').' #'.$pid), 'count' => (int) $c]);
        }

        return $rows->sortByDesc('count')->values()->all();
    }

    private function featureAdoption(): array
    {
        $totalRestaurants = max(1, Store::excludingSandbox()->count()); // F5-5
        $d30 = Carbon::now()->subDays(30);
        $realOrders = fn () => SandboxStores::exclude(Order::query(), 'store_id');

        $rows = [
            ['key' => 'ordering',     'label' => ___('Online ordering (30 days)'),   'count' => $realOrders()->where('created_at', '>=', $d30)->distinct('store_id')->count('store_id')],
            ['key' => 'pos',          'label' => ___('POS (30 days)'),               'count' => $realOrders()->where('source', 'pos')->where('created_at', '>=', $d30)->distinct('store_id')->count('store_id')],
            ['key' => 'kds',          'label' => ___('Kitchen display'),             'count' => KitchenStation::where('active', true)->distinct('store_id')->count('store_id')],
            ['key' => 'whatsapp',     'label' => ___('WhatsApp (30 days)'),          'count' => WhatsAppUsage::where('created_at', '>=', $d30)->whereNotNull('store_id')->distinct('store_id')->count('store_id')],
            ['key' => 'branches',     'label' => ___('Multiple branches'),           'count' => Branch::selectRaw('store_id')->groupBy('store_id')->havingRaw('COUNT(*) > 1')->get()->count()],
            ['key' => 'loyalty',      'label' => ___('Loyalty program'),             'count' => LoyaltyProgram::where('active', true)->count()],
            ['key' => 'reservations', 'label' => ___('Reservations (30 days)'),      'count' => Reservation::where('created_at', '>=', $d30)->distinct('store_id')->count('store_id')],
            ['key' => 'inventory',    'label' => ___('Inventory tracking'),          'count' => Ingredient::distinct('store_id')->count('store_id')],
            ['key' => 'staff',        'label' => ___('Staff accounts'),              'count' => StaffMember::where('active', true)->distinct('store_id')->count('store_id')],
            ['key' => 'drivers',      'label' => ___('Delivery drivers'),            'count' => Driver::where('active', true)->distinct('owner_id')->count('owner_id')],
        ];

        foreach ($rows as &$row) {
            $row['pct'] = min(100, (int) round($row['count'] / $totalRestaurants * 100));
        }

        usort($rows, fn ($a, $b) => $b['count'] <=> $a['count']);

        return $rows;
    }

    private function operations(): array
    {
        $d7 = Carbon::now()->subDays(7);
        $d24 = Carbon::now()->subDay();

        $deliveries7 = WebhookDelivery::where('created_at', '>=', $d7)->count();
        $failed7 = WebhookDelivery::where('created_at', '>=', $d7)->where('success', false)->count();
        $rate = $deliveries7 > 0 ? (int) round(($deliveries7 - $failed7) / $deliveries7 * 100) : null;

        $aiCalls = AiUsage::where('created_at', '>=', $d7)->count();
        $aiTokens = (int) AiUsage::where('created_at', '>=', $d7)->sum(\DB::raw('prompt_tokens + completion_tokens'));

        $waMessages = (int) WhatsAppUsage::where('created_at', '>=', $d7)->sum('message_count');
        $announcementsLive = Hooks::first(self::ANNOUNCEMENTS_LIVE_SLOT);
        $resellersActive = Hooks::first(self::RESELLERS_ACTIVE_SLOT);

        return [
            [
                // MC-30 (rule 0): the FX feed's health, so an owner can see it is
                // alive + fresh without running artisan.
                'key' => 'fx',
                'label' => ___('FX rates feed'),
                'value' => ExchangeRateService::lastFetchedAt()?->diffForHumans() ?? ___('Never'),
                'sub' => ExchangeRateService::isStale() ? ___('Stale') : ___('Fresh'),
                'state' => ExchangeRateService::isStale() ? 'warn' : 'ok',
                'route' => null,
            ],
            [
                'key' => 'webhooks',
                'label' => ___('Webhook delivery (7 days)'),
                'value' => $rate === null ? '—' : $rate.'%',
                'sub' => $deliveries7 > 0 ? format_number_count($deliveries7).' '.___('sent').' · '.format_number_count($failed7).' '.___('failed') : ___('No deliveries yet'),
                'state' => $rate === null ? null : ($rate >= 98 ? 'ok' : ($rate >= 90 ? 'warn' : 'bad')),
                'route' => null,
            ],
            [
                'key' => 'whatsapp',
                'label' => ___('WhatsApp messages (7 days)'),
                'value' => format_number_count($waMessages),
                'sub' => ___('across all providers'),
                'state' => null,
                'route' => route('admin.whatsapp.settings'),
            ],
            [
                'key' => 'ai',
                'label' => ___('AI generations (7 days)'),
                'value' => format_number_count($aiCalls),
                'sub' => format_number_count($aiTokens).' '.___('tokens'),
                'state' => null,
                'route' => null,
            ],
            [
                'key' => 'campaigns',
                'label' => ___('Campaigns in flight'),
                'value' => format_number_count(Campaign::whereIn('status', ['scheduled', 'sending'])->count()),
                'sub' => format_number_count(Campaign::where('status', 'sent')->where('updated_at', '>=', $d7)->count()).' '.___('sent this week'),
                'state' => null,
                'route' => null,
            ],
            [
                'key' => 'experiments',
                'label' => ___('A/B experiments'),
                'value' => format_number_count(AbExperiment::where('status', 'active')->count()),
                'sub' => ___('running now'),
                'state' => null,
                'route' => route('admin.ab-experiments.index'),
            ],
            // P1-13 13e: platform announcements are the ee Platform module's (it answers the count); without it no tile.
            ...($announcementsLive === null ? [] : [[
                'key' => 'announcements',
                'label' => ___('Announcements live'),
                'value' => format_number_count($announcementsLive),
                'sub' => ___('visible to owners'),
                'state' => null,
                'route' => route('admin.announcements.index'),
            ]]),
            // P1-13 13f: resellers are the ee Reseller module's (it answers the active count); without it no tile.
            ...($resellersActive === null ? [] : [[
                'key' => 'resellers',
                'label' => ___('Active resellers'),
                'value' => format_number_count($resellersActive),
                'sub' => format_number_count(User::whereNotNull('reseller_id')->count()).' '.___('seats'),
                'state' => null,
                'route' => route('admin.resellers.index'),
            ]]),
            [
                'key' => 'api',
                'label' => ___('API tokens'),
                'value' => format_number_count(ApiToken::where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now()))->count()),
                'sub' => format_number_count(WebhookEndpoint::where('active', true)->count()).' '.___('webhook endpoints'),
                'state' => null,
                'route' => null,
            ],
            [
                'key' => 'activity',
                'label' => ___('Owner actions (24 hours)'),
                'value' => format_number_count(ActivityLog::where('created_at', '>=', $d24)->count()),
                'sub' => ___('across all restaurants'),
                'state' => null,
                'route' => null,
            ],
        ];
    }

    /* ------------------------------------------------------------------ */

    /** Direction + formatted percentage against the previous window. */
    private function delta(float $current, float $previous): array
    {
        if ($previous <= 0) {
            return [
                'dir' => $current > 0 ? 'up' : 'flat',
                'pct' => $current > 0 ? null : 0,
            ];
        }

        $pct = ($current - $previous) / $previous * 100;

        return [
            'dir' => abs($pct) < 0.5 ? 'flat' : ($pct > 0 ? 'up' : 'down'),
            'pct' => (int) round(abs($pct)),
        ];
    }

    /** 30-day daily series → SVG polyline points in a 100×28 viewBox. */
    private function sparkline($byDate, Carbon $from): ?array
    {
        $values = [];
        for ($i = 0; $i < 30; $i++) {
            $values[] = (float) ($byDate[$from->copy()->addDays($i)->format('Y-m-d')] ?? 0);
        }

        $max = max($values);
        if ($max <= 0) {
            return null;
        }

        $points = [];
        foreach ($values as $i => $v) {
            $x = round($i / 29 * 100, 1);
            $y = round(26 - ($v / $max * 24), 1); // 2px padding top + bottom
            $points[] = $x.','.$y;
        }

        return [
            'points' => implode(' ', $points),
            'last' => end($points),
        ];
    }

    /** Fill a pluck()ed DATE→value map into a dense per-day array. */
    private function dailySeries($byDate, Carbon $from, int $days): array
    {
        $out = [];
        for ($i = 0; $i < $days; $i++) {
            $out[] = (float) ($byDate[$from->copy()->addDays($i)->format('Y-m-d')] ?? 0);
        }

        return $out;
    }
}
