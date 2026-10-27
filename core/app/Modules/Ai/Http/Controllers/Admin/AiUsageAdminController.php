<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Ai\Http\Controllers\Admin;

use Juwri\Core\Http\Controllers\Controller;
use Juwri\Modules\Ai\Models\AiUsage;
use Juwri\Core\Models\User;
use Illuminate\Http\Request;

/**
 * Superadmin AI usage observability: aggregate generations/tokens per
 * provider, feature and user over a selectable range, plus the latest
 * raw generations. Backed by the ai_usage ledger the AI provider layer
 * writes on every call.
 */
class AiUsageAdminController extends Controller
{
    /** Selectable ranges (days); anything else falls back to the default. */
    private const RANGES = [7, 30, 90];

    public function index(Request $request)
    {
        $days = (int) $request->get('days', 30);
        if (!in_array($days, self::RANGES, true)) {
            $days = 30;
        }
        $since = now()->subDays($days);

        $base = AiUsage::where('created_at', '>=', $since);

        /* Stats row */
        $totalGenerations = (clone $base)->count();
        $totalTokens      = (int) (clone $base)->selectRaw('COALESCE(SUM(prompt_tokens + completion_tokens), 0) AS t')->value('t');
        $fallbacks        = (clone $base)->where('fell_back', true)->count();
        $fallbackRate     = $totalGenerations > 0 ? round($fallbacks / $totalGenerations * 100, 1) : 0.0;
        $distinctUsers    = (clone $base)->whereNotNull('user_id')->distinct()->count('user_id');

        /* Aggregate cards */
        $byProvider = (clone $base)
            ->selectRaw('provider, COUNT(*) AS generations, COALESCE(SUM(prompt_tokens + completion_tokens), 0) AS tokens, COALESCE(SUM(CASE WHEN fell_back THEN 1 ELSE 0 END), 0) AS fallbacks')
            ->groupBy('provider')
            ->orderByDesc('generations')
            ->get();

        $byFeature = (clone $base)
            ->selectRaw('feature, COUNT(*) AS generations, COALESCE(SUM(prompt_tokens + completion_tokens), 0) AS tokens')
            ->groupBy('feature')
            ->orderByDesc('generations')
            ->get();

        $topUsers = (clone $base)
            ->selectRaw('user_id, COUNT(*) AS generations, COALESCE(SUM(prompt_tokens + completion_tokens), 0) AS tokens')
            ->groupBy('user_id')
            ->orderByDesc('generations')
            ->take(10)
            ->get();

        /* Recent generations */
        $recent = (clone $base)->orderByDesc('created_at')->orderByDesc('id')->take(25)->get();

        // One whereIn lookup covers both cards (null user_id = system call).
        $userIds = $topUsers->pluck('user_id')->merge($recent->pluck('user_id'))->filter()->unique();
        $users   = User::whereIn('id', $userIds)->get(['id', 'name', 'email'])->keyBy('id');

        return view('admin.ai-usage.index', compact(
            'days',
            'totalGenerations',
            'totalTokens',
            'fallbackRate',
            'distinctUsers',
            'byProvider',
            'byFeature',
            'topUsers',
            'recent',
            'users'
        ));
    }
}
