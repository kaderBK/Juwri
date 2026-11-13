<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace App\Http\Controllers\Admin;

use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\AbuseEvent;
use Juwri\Modules\Ai\Models\AiUsage;
use Juwri\Core\Models\TenantUsageDaily;
use Juwri\Core\Models\User;
use Juwri\Core\Services\AbuseGuard;
use App\Services\PlatformMetrics;
use Illuminate\Http\Request;
use Juwri\Core\Settings\PlatformSettings;

/**
 * Abuse Guard P1 (1.3/1.4): super-admin config page. Skin v2, five tabs
 * (Rate Limits · AI Spend · Signup Guard · Queue & Uploads · Events). Every
 * setting persists through the Options system (PlatformSettings::set) and reads
 * back at config('settings.abuse_*'). Every guard ships in WARN mode by default.
 *
 * P1 delivers the shell + the Rate Limits tab fields + a per-guard mode select
 * and a "events in last 24h" badge. Later phases fill the other tabs.
 */
class AbuseGuardController extends Controller
{
    /** Named rate limiters: guard key => [label, default per-min, default per-hour]. */
    public const LIMITERS = [
        'ai' => ['AI generation / scan / translate', 10, 100],
        'export' => ['CSV / PDF exports', 5, 30],
        'upload' => ['Media / file uploads', 20, 200],
        'public_write' => ['Reviews / waitlist / reservations / contact / OTP', 10, 60],
        'signup' => ['Public signups (partners / reseller / sale link)', 5, 15],
        'webhook_in' => ['Inbound webhooks / IPN', 120, 2000],
        'mcp' => ['MCP endpoints', 60, 1000],
        'dashboard_poll' => ['Dashboard / KDS / bell live-pollers', 60, 1200],
    ];

    public function index(Request $request)
    {
        $tab = $request->query('tab', 'rate-limits');

        return view('admin.abuse_guard.index', [
            'tab' => $tab,
            'limiters' => self::LIMITERS,
            'modes' => $this->modes(),
            'counts' => $this->counts(),
            'val' => fn ($key, $d) => config('settings.'.$key, $d),
            'extra' => $tab === 'rate-limits' ? $this->extraLimiterReadouts() : [],
            'aiSpend' => $tab === 'ai-spend' ? $this->aiSpendReadouts() : [],
            'signup' => $tab === 'signup' ? $this->signupReadouts() : [],
            'queue' => $tab === 'queue' ? $this->queueReadouts() : [],
            'events' => $tab === 'events' ? $this->eventsReadouts($request) : [],
            'usage' => $tab === 'resource-usage' ? $this->resourceUsageReadouts($request) : [],
        ]);
    }

    /** Resource Usage tab: top tenants per metric + optional 14-day drill-down. */
    protected function resourceUsageReadouts(Request $request): array
    {
        $metrics = ['requests_public', 'requests_dashboard', 'requests_api', 'ai_credits', 'storage_mb', 'db_rows', 'orders_failed'];
        $today = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();

        $top = [];
        foreach ($metrics as $metric) {
            $top[$metric] = TenantUsageDaily::whereDate('date', $today)
                ->where('metric', $metric)
                ->orderByDesc('value')
                ->limit(10)
                ->get(['user_id', 'value']);
        }

        // Enrich the tenants on show ONCE (batched — no per-row query in the view):
        // identity (a bare user id is unactionable), plan/tier, month-to-date usage,
        // and today's share of the platform per metric (the heavy-tenant signal).
        $ids = collect($top)->flatMap(fn ($rows) => $rows->pluck('user_id'))->unique()->values();
        $users = User::whereIn('id', $ids)->get(['id', 'name', 'email', 'group_id', 'reseller_id', 'created_at'])->keyBy('id');

        // MTD per (user, metric): one grouped query for the whole month.
        $mtd = TenantUsageDaily::whereIn('user_id', $ids)
            ->where('date', '>=', $monthStart)
            ->selectRaw('user_id, metric, sum(value) as c')
            ->groupBy('user_id', 'metric')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->pluck('c', 'metric'));

        // Plan label + tier per tenant (config free/trial plans resolve here too).
        $planFor = [];
        foreach ($users as $id => $u) {
            $planFor[$id] = $this->tenantPlanLabel($u);
        }

        // Today's platform total per metric → each row's % share.
        $totals = [];
        foreach ($metrics as $metric) {
            $totals[$metric] = max(1, (int) TenantUsageDaily::whereDate('date', $today)->where('metric', $metric)->sum('value'));
        }

        // Annotate every row in place (via setAttribute so the dynamic columns are
        // real model attributes the blade reads as $r->tenant_name, not undefined
        // properties) so the view stays a plain table.
        foreach ($top as $metric => $rows) {
            foreach ($rows as $r) {
                $u = $users[$r->user_id] ?? null;
                $r->setAttribute('tenant_name', $u->name ?? null);
                $r->setAttribute('tenant_email', $u->email ?? null);
                $r->setAttribute('reseller_id', $u->reseller_id ?? null);
                $r->setAttribute('plan', $planFor[$r->user_id]['label'] ?? '—');
                $r->setAttribute('tier', $planFor[$r->user_id]['tier'] ?? 'unknown');
                $r->setAttribute('mtd', (int) ($mtd[$r->user_id][$metric] ?? 0));
                $r->setAttribute('share_pct', round(100 * $r->value / $totals[$metric], 1));
            }
        }

        // Heavy-tenant banner (reuse the alert calc).
        $heavy = collect(PlatformMetrics::evaluateAlerts())
            ->filter(fn ($a) => str_contains($a, 'heavy') || str_contains($a, 'request surge'))->values()->all();

        // Optional per-tenant 14-day drill-down, with the tenant's own identity/plan.
        $drill = null;
        if ($uid = (int) $request->query('tenant')) {
            $du = User::find($uid);
            $dPlan = $du ? $this->tenantPlanLabel($du) : ['label' => '—', 'tier' => 'unknown'];
            $drill = [
                'user_id' => $uid,
                'name' => $du->name ?? null,
                'email' => $du->email ?? null,
                'plan' => $dPlan['label'],
                'tier' => $dPlan['tier'],
                'mtd' => TenantUsageDaily::where('user_id', $uid)
                    ->where('date', '>=', $monthStart)
                    ->selectRaw('metric, sum(value) as c')
                    ->groupBy('metric')->pluck('c', 'metric'),
                'series' => TenantUsageDaily::where('user_id', $uid)
                    ->where('date', '>=', now()->subDays(14)->toDateString())
                    ->orderBy('date')
                    ->get(['date', 'metric', 'value']),
            ];
        }

        return ['metrics' => $metrics, 'top' => $top, 'heavy' => $heavy, 'drill' => $drill];
    }

    /**
     * A tenant's plan label + tier for the abuse dashboard. Resolves through the
     * same plan() the platform gates on (config Free/Trial plans included), and
     * classifies the tier so a FREE tenant taking an outsized share — the actual
     * abuse pattern — reads at a glance. Best-effort: never breaks the page.
     *
     * @return array{label:string, tier:string}
     */
    protected function tenantPlanLabel(User $u): array
    {
        try {
            $plan = $u->plan();
            $label = is_object($plan) ? (string) ($plan->name ?? $plan->id ?? 'Free') : 'Free';
            $gid = (string) ($u->group_id ?? '');
            $tier = $gid === 'free' ? 'free'
                : ($gid === 'trial' ? 'trial'
                : ((is_object($plan) && (float) ($plan->monthly_price ?? 0) <= 0 && (float) ($plan->lifetime_price ?? 0) <= 0) ? 'free' : 'paid'));

            return ['label' => $label, 'tier' => $tier];
        } catch (\Throwable $e) {
            return ['label' => '—', 'tier' => 'unknown'];
        }
    }

    /** Events tab: paginated abuse_events (guard filter, last 7 days) + 24h counts. */
    protected function eventsReadouts(Request $request): array
    {
        $filter = $request->query('guard');

        $query = AbuseEvent::where('created_at', '>=', now()->subDays(7))
            ->orderByDesc('created_at');
        if ($filter) {
            $query->where('guard', $filter);
        }

        $counts = AbuseEvent::where('created_at', '>=', now()->subHours(24))
            ->selectRaw('guard, count(*) as c')
            ->groupBy('guard')
            ->pluck('c', 'guard')
            ->toArray();

        return [
            'rows' => $query->paginate(30)->appends(['tab' => 'events', 'guard' => $filter]),
            'guards' => AbuseEvent::where('created_at', '>=', now()->subDays(7))->distinct()->pluck('guard')->sort()->values(),
            'filter' => $filter,
            'counts' => $counts,
            'alerts' => [
                'signups_per_hour' => config('settings.abuse_alert_signups_per_hour', 30),
                'user_events_per_day' => config('settings.abuse_alert_user_events_per_day', 50),
                'heavy_tenant_pct' => config('settings.abuse_heavy_tenant_pct', 10),
                'heavy_tenant_ai_pct' => config('settings.abuse_heavy_tenant_ai_pct', 25),
                'retention_days' => config('settings.abuse_events_retention_days', 90),
            ],
        ];
    }

    /** Persist the Events tab's alert thresholds + retention. */
    public function updateAlerts(Request $request)
    {
        $payload = [
            'abuse_alert_signups_per_hour' => max(0, (int) $request->input('signups_per_hour', 30)),
            'abuse_alert_user_events_per_day' => max(0, (int) $request->input('user_events_per_day', 50)),
            'abuse_heavy_tenant_pct' => max(0, (float) $request->input('heavy_tenant_pct', 10)),
            'abuse_heavy_tenant_ai_pct' => max(0, (float) $request->input('heavy_tenant_ai_pct', 25)),
            'abuse_events_retention_days' => max(0, (int) $request->input('retention_days', 90)),
        ];

        foreach ($payload as $key => $value) {
            PlatformSettings::set($key, $value);
        }

        return back()->with('success', 'Alert settings saved.');
    }

    /** Queue & Uploads tab. */
    protected function queueReadouts(): array
    {
        return [
            'queue_mode' => AbuseGuard::mode('queue'),
            'upload_mode' => AbuseGuard::mode('upload'),
            'storage_mode' => AbuseGuard::mode('storage'),
            'events_24h' => AbuseGuard::recentCount('queue', 24) + AbuseGuard::recentCount('storage', 24),
            'fields' => [
                'queue_tenant_pending_cap' => AbuseGuard::value('queue', 'tenant_pending_cap', 10),
                'upload_max_mb' => AbuseGuard::value('upload', 'max_mb', 8),
                'upload_max_pixels' => AbuseGuard::value('upload', 'max_pixels', 25000000),
                'scan_max_pages' => AbuseGuard::value('scan', 'max_pages', 30),
                'storage_tenant_cap_mb' => AbuseGuard::value('storage', 'tenant_cap_mb', 500),
            ],
        ];
    }

    /** Signup Guard tab: mode + velocity + Turnstile keys. */
    protected function signupReadouts(): array
    {
        return [
            'mode' => AbuseGuard::mode('signup'),
            'events_24h' => AbuseGuard::recentCount('signup', 24),
            'fields' => [
                'velocity_per_day' => AbuseGuard::value('signup', 'velocity_per_day', 3),
                'turnstile_site_key' => config('settings.abuse_turnstile_site_key', ''),
                'turnstile_secret_key' => config('settings.abuse_turnstile_secret_key', ''),
            ],
        ];
    }

    /** AI Spend tab: current settings + today's usage readouts. */
    protected function aiSpendReadouts(): array
    {
        return [
            'mode' => AbuseGuard::mode('ai_spend'),
            'events_24h' => AbuseGuard::recentCount('ai_spend', 24),
            'today_total' => AiUsage::where('created_at', '>=', now()->startOfDay())->count(),
            'today_users' => AiUsage::where('created_at', '>=', now()->startOfDay())->distinct('user_id')->count('user_id'),
            'fields' => [
                'user_daily_cap' => AbuseGuard::value('ai_spend', 'user_daily_cap', 200),
                'tenant_daily_cap' => AbuseGuard::value('ai_spend', 'tenant_daily_cap', 1000),
                'platform_daily_cap' => AbuseGuard::value('ai_spend', 'platform_daily_cap', 10000),
                'anomaly_multiplier' => AbuseGuard::value('ai_spend', 'anomaly_multiplier', 10),
                'max_input_chars' => AbuseGuard::value('ai_spend', 'max_input_chars', 40000),
                'max_tokens' => AbuseGuard::value('ai_spend', 'max_tokens', 2048),
            ],
        ];
    }

    /** Rate Limits tab, second card: branch ceilings + bulk messaging + webhook payload cap. */
    protected function extraLimiterReadouts(): array
    {
        return [
            'branch_mode' => AbuseGuard::mode('branch'),
            'branch_events' => AbuseGuard::recentCount('branch', 24),
            'messaging_mode' => AbuseGuard::mode('messaging'),
            'messaging_events' => AbuseGuard::recentCount('messaging', 24),
            'fields' => [
                'branch_orders_per_min' => AbuseGuard::value('branch', 'orders_per_min', 300),
                'branch_waitlist_per_min' => AbuseGuard::value('branch', 'waitlist_per_min', 60),
                'branch_reservation_per_min' => AbuseGuard::value('branch', 'reservation_per_min', 60),
                'messaging_tenant_daily_cap' => AbuseGuard::value('messaging', 'tenant_daily_cap', 5000),
                'webhook_in_max_kb' => AbuseGuard::value('rl', 'webhook_in_max_kb', 64),
            ],
        ];
    }

    /** Current mode per guard (for the selects). */
    protected function modes(): array
    {
        $out = [];
        foreach (array_keys(self::LIMITERS) as $g) {
            $out[$g] = AbuseGuard::mode($g);
        }

        return $out;
    }

    /** 24h event count per guard (for the badges). */
    protected function counts(): array
    {
        $out = [];
        foreach (array_keys(self::LIMITERS) as $g) {
            $out[$g] = AbuseGuard::recentCount($g, 24);
        }

        return $out;
    }

    /** Persist the Rate Limits tab. */
    public function updateRateLimits(Request $request)
    {
        $payload = [];

        foreach (self::LIMITERS as $guard => [$label, $perMin, $perHour]) {
            $mode = $request->input($guard.'_mode', AbuseGuard::WARN);
            $payload['abuse_'.$guard.'_mode'] = in_array($mode, [AbuseGuard::OFF, AbuseGuard::WARN, AbuseGuard::ENFORCE], true) ? $mode : AbuseGuard::WARN;

            $payload['abuse_rl_'.$guard.'_per_min'] = max(0, (int) $request->input($guard.'_per_min', $perMin));
            $payload['abuse_rl_'.$guard.'_per_hour'] = max(0, (int) $request->input($guard.'_per_hour', $perHour));
        }

        // Second card: branch ceilings + bulk messaging + webhook payload cap.
        $modeOf = fn ($v) => in_array($v, [AbuseGuard::OFF, AbuseGuard::WARN, AbuseGuard::ENFORCE], true) ? $v : AbuseGuard::WARN;
        $payload['abuse_branch_mode'] = $modeOf($request->input('branch_mode', AbuseGuard::WARN));
        $payload['abuse_messaging_mode'] = $modeOf($request->input('messaging_mode', AbuseGuard::WARN));
        $payload['abuse_branch_orders_per_min'] = max(0, (int) $request->input('branch_orders_per_min', 300));
        $payload['abuse_branch_waitlist_per_min'] = max(0, (int) $request->input('branch_waitlist_per_min', 60));
        $payload['abuse_branch_reservation_per_min'] = max(0, (int) $request->input('branch_reservation_per_min', 60));
        $payload['abuse_messaging_tenant_daily_cap'] = max(0, (int) $request->input('messaging_tenant_daily_cap', 5000));
        $payload['abuse_rl_webhook_in_max_kb'] = max(0, (int) $request->input('webhook_in_max_kb', 64));

        foreach ($payload as $key => $value) {
            PlatformSettings::set($key, $value);
        }

        return back()->with('success', 'Rate limit settings saved.');
    }

    /** Persist the AI Spend tab. */
    public function updateAiSpend(Request $request)
    {
        $mode = $request->input('ai_spend_mode', AbuseGuard::WARN);
        $payload = [
            'abuse_ai_spend_mode' => in_array($mode, [AbuseGuard::OFF, AbuseGuard::WARN, AbuseGuard::ENFORCE], true) ? $mode : AbuseGuard::WARN,
            'abuse_ai_spend_user_daily_cap' => max(0, (int) $request->input('user_daily_cap', 200)),
            'abuse_ai_spend_tenant_daily_cap' => max(0, (int) $request->input('tenant_daily_cap', 1000)),
            'abuse_ai_spend_platform_daily_cap' => max(0, (int) $request->input('platform_daily_cap', 10000)),
            'abuse_ai_spend_anomaly_multiplier' => max(0, (float) $request->input('anomaly_multiplier', 10)),
            'abuse_ai_spend_max_input_chars' => max(0, (int) $request->input('max_input_chars', 40000)),
            'abuse_ai_spend_max_tokens' => max(0, (int) $request->input('max_tokens', 2048)),
        ];

        foreach ($payload as $key => $value) {
            PlatformSettings::set($key, $value);
        }

        return back()->with('success', 'AI spend settings saved.');
    }

    /** Persist the Signup Guard tab. */
    public function updateSignup(Request $request)
    {
        $mode = $request->input('signup_mode', AbuseGuard::WARN);
        $payload = [
            'abuse_signup_mode' => in_array($mode, [AbuseGuard::OFF, AbuseGuard::WARN, AbuseGuard::ENFORCE], true) ? $mode : AbuseGuard::WARN,
            'abuse_signup_velocity_per_day' => max(0, (int) $request->input('velocity_per_day', 3)),
            'abuse_turnstile_site_key' => trim((string) $request->input('turnstile_site_key', '')),
            'abuse_turnstile_secret_key' => trim((string) $request->input('turnstile_secret_key', '')),
        ];

        foreach ($payload as $key => $value) {
            PlatformSettings::set($key, $value);
        }

        return back()->with('success', 'Signup guard settings saved.');
    }

    /** Persist the Queue & Uploads tab. */
    public function updateQueue(Request $request)
    {
        $modeOf = fn ($v) => in_array($v, [AbuseGuard::OFF, AbuseGuard::WARN, AbuseGuard::ENFORCE], true) ? $v : AbuseGuard::WARN;
        $payload = [
            'abuse_queue_mode' => $modeOf($request->input('queue_mode', AbuseGuard::WARN)),
            'abuse_upload_mode' => $modeOf($request->input('upload_mode', AbuseGuard::WARN)),
            'abuse_storage_mode' => $modeOf($request->input('storage_mode', AbuseGuard::WARN)),
            'abuse_queue_tenant_pending_cap' => max(0, (int) $request->input('queue_tenant_pending_cap', 10)),
            'abuse_upload_max_mb' => max(0, (int) $request->input('upload_max_mb', 8)),
            'abuse_upload_max_pixels' => max(0, (int) $request->input('upload_max_pixels', 25000000)),
            'abuse_scan_max_pages' => max(0, (int) $request->input('scan_max_pages', 30)),
            'abuse_storage_tenant_cap_mb' => max(0, (int) $request->input('storage_tenant_cap_mb', 500)),
        ];

        foreach ($payload as $key => $value) {
            PlatformSettings::set($key, $value);
        }

        return back()->with('success', 'Queue & upload settings saved.');
    }
}
