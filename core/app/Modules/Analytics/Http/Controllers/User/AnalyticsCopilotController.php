<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Analytics\Http\Controllers\User;

use App\Models\Order;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Juwri\Core\Http\Controllers\Controller;
use Juwri\Core\Models\Store;
use Juwri\Core\Support\Hooks;
use Juwri\Modules\Ai\Contracts\Ai;

/**
 * K9 (dark): the AI analytics copilot + cross-store benchmark card. The copilot
 * answers natural-language questions grounded ONLY in the current store's own
 * aggregates (never raw multi-tenant rows); the benchmark card renders same-cohort
 * medians from PeerBenchmark, which is published only above the k-anonymity floor.
 * Gated OFF via config('analytics.copilot_enabled').
 * P1-13 13h: peer benchmarks (cross-business) are ee — the cohort's published medians come from the Analytics ee
 * module through {@see self::PUBLISHED_BENCHMARK_SLOT}; Community has none, so the card is simply absent.
 */
class AnalyticsCopilotController extends Controller
{
    /** (cohort, currency) → ['median_aov' => float, 'cohort_n' => int] above the k-anonymity floor, else null. */
    public const PUBLISHED_BENCHMARK_SLOT = 'analytics.peer-benchmark.published';

    public function __construct()
    {
        $this->activeTheme = active_theme();
    }

    private function enabled(): bool
    {
        return (bool) config('analytics.copilot_enabled', false);
    }

    public function index(Request $request): View
    {
        abort_unless($this->enabled(), 404);
        $store = dashboard_current_store();
        abort_unless($store !== null, 404, 'Pick a store first.');

        return view('owner.reports.insights', [
            'store' => $store,
            'benchmark' => $this->benchmarkFor($store),
        ]);
    }

    public function ask(Request $request): JsonResponse
    {
        abort_unless($this->enabled(), 404);
        $store = dashboard_current_store();
        abort_unless($store !== null, 404);

        $q = trim((string) $request->input('question', ''));
        if ($q === '') {
            return response()->json(['success' => false, 'message' => ___('Ask a question about your sales.')], 422);
        }

        $figures = $this->groundedContext($store);
        $system = 'You are a concise analytics assistant for ONE restaurant. Answer only from the JSON figures for THIS store. '
            .'If the question is about another store, another business, or data not in the figures, say you cannot answer that. '
            .'Figures: '.json_encode($figures);

        try {
            $answer = app(Ai::class)->complete(
                [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => mb_substr($q, 0, 500)],
                ],
                ['max_tokens' => 320],
                (int) $store->user_id,
                (int) $store->id,
                'analytics_copilot'
            );
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => ___('The assistant is unavailable right now. Please try again.')], 503);
        }

        return response()->json(['success' => true, 'answer' => $answer, 'figures' => $figures]);
    }

    /**
     * Store-scoped aggregates only — the grounding the model is allowed to see.
     *
     * @return array<string, mixed>
     */
    private function groundedContext(Store $store): array
    {
        $from = now()->subDays(30);
        $count = Order::where('store_id', $store->id)->where('created_at', '>=', $from)->count();
        $sales = round(Order::revenueSum(Order::where('store_id', $store->id)->where('created_at', '>=', $from)), 2);

        return [
            'store' => $store->title,
            'period_days' => 30,
            'orders' => $count,
            'sales' => $sales,
            'avg_order_value' => $count ? round($sales / $count, 2) : 0,
            'currency' => config('settings.currency_code'),
        ];
    }

    /**
     * Same-cohort benchmark, only above the k-anonymity floor.
     *
     * @return array<string, bool|float|int|string>|null
     */
    private function benchmarkFor(Store $store): ?array
    {
        $from = now()->subDays(30);
        $count = Order::where('store_id', $store->id)->where('created_at', '>=', $from)->count();
        $cohort = peer_benchmark_cohort($count);
        // MC-43: benchmark against same-currency peers only (medians are partitioned
        // by currency now), so an OMR store never compares its AOV to a USD median.
        $currency = strtoupper(store_currency($store->id));
        $pb = Hooks::first(self::PUBLISHED_BENCHMARK_SLOT, $cohort, $currency);

        if (! is_array($pb)) {
            return null; // no published cohort / sparse cohort → never a raw peer figure
        }

        $sales = Order::revenueSum(Order::where('store_id', $store->id)->where('created_at', '>=', $from));
        $myAov = $count ? round($sales / $count, 2) : 0.0;

        return [
            'cohort' => $cohort,
            'currency' => $currency,
            'my_aov' => $myAov,
            'median_aov' => (float) $pb['median_aov'],
            'cohort_n' => (int) $pb['cohort_n'],
            'above_median' => $myAov >= (float) $pb['median_aov'],
        ];
    }
}
