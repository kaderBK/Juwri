<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Core\Services;

use App\Models\Theme;
use App\Models\ThemeVersion;
use Illuminate\Support\Facades\DB;

/**
 * P5-5: abuse monitoring on the P5-3 counters.
 *
 *  - CAP-BREACH sellers: a published theme whose renders keep dying by budget
 *    (render.fail spikes) is broken or hostile. Above the threshold it is
 *    flagged (alert log) and — behind an explicit config switch, default OFF —
 *    auto-delisted so the resolver stops serving it (diners fall back to the
 *    first-party Blade theme; nothing blanks).
 *  - PHONE-HOME sellers: the linter bans external URLs at submission and the
 *    storefront CSP reports what slips through anyway; csp.violation spikes
 *    per blocked host surface here.
 */
class AbuseMonitor
{
    /**
     * @return array{flagged: list<array{slug:string, fails:int, delisted:bool}>, phone_home: list<array{host:string, count:int}>}
     */
    public static function scan(): array
    {
        $maxFails = (int) config('platform.alerts.max_theme_render_fails', 25);
        $autoDelist = (bool) config('platform.alerts.auto_delist_breaching_themes', false);

        $flagged = [];
        $rows = DB::table('platform_metrics')
            ->where('day', now()->toDateString())
            ->where('metric', 'render.fail')
            ->where('count', '>=', $maxFails)
            ->get();

        foreach ($rows as $row) {
            $slug = (string) $row->label;
            $delisted = false;

            if ($autoDelist && ($theme = Theme::where('slug', $slug)->first())) {
                // Only status may move on published versions (immutability guard).
                ThemeVersion::where('theme_id', $theme->id)
                    ->where('status', 'published')
                    ->get()
                    ->each(fn (ThemeVersion $v) => $v->update(['status' => 'delisted']));
                $theme->update(['status' => 'delisted']);
                $delisted = true;
            }

            logger()->error(sprintf(
                '[platform-alert] theme "%s" breached the render budget %d times today%s',
                $slug,
                (int) $row->count,
                $delisted ? ' — AUTO-DELISTED' : ' — flagged for review'
            ));

            $flagged[] = ['slug' => $slug, 'fails' => (int) $row->count, 'delisted' => $delisted];
        }

        $phoneHome = [];
        $maxViolations = (int) config('platform.alerts.max_csp_violations_per_host', 50);
        $hosts = DB::table('platform_metrics')
            ->where('day', now()->toDateString())
            ->where('metric', 'csp.violation')
            ->where('count', '>=', $maxViolations)
            ->get();
        foreach ($hosts as $row) {
            // Informational abuse signal, not an application error: the CSP is
            // doing its job blocking a store's third-party beacon (analytics,
            // inline). Logged at WARNING so it stays visible without inflating the
            // production error count (the prod CPU/cache watch tracks ERROR volume).
            logger()->warning(sprintf(
                '[platform-alert] CSP blocked %d requests to "%s" today (phone-home signal)',
                (int) $row->count,
                $row->label
            ));
            $phoneHome[] = ['host' => (string) $row->label, 'count' => (int) $row->count];
        }

        return ['flagged' => $flagged, 'phone_home' => $phoneHome];
    }
}
