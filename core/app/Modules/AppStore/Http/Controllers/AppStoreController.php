<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\AppStore\Http\Controllers;

use App\Models\App;
use App\Models\AppInstallation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Juwri\Core\Http\Controllers\Controller;
use App\Http\Controllers\MarketplaceController;

/**
 * stores P2: the PUBLIC App Store (apps.shopify.com-level) over the existing app
 * platform. `/apps` stays the logged-in install dashboard; this is the browse +
 * detail surface, approved apps only. Money paths (AppInstallService /
 * AppBillingService) are unchanged — this layer only lists and links to them.
 * Routes register before the {slug} catch-all.
 */
class AppStoreController extends Controller
{
    public function index(Request $request)
    {
        $catsVocab = config('marketplace.app_categories');

        $q = trim((string) $request->query('q', ''));
        $pricing = (string) $request->query('pricing', '');                      // '' | free | paid
        $category = array_values(array_intersect((array) $request->query('category', []), $catsVocab));
        $sort = (string) $request->query('sort', 'featured');
        $minRating = (int) $request->query('min_rating', 0);                     // 0=any | 2 | 3 | 4

        // Fresh base each call: approved apps matching the text search.
        $base = fn () => App::query()
            ->where('status', 'approved')
            ->when(\App\Support\SearchTerm::clean($q) !== '', fn ($query) => \App\Support\SearchTerm::apply($query, ['name', 'tagline', 'slug'], $q));

        $applyPricing = fn ($query) => match ($pricing) {
            'free'  => $query->where('billing_type', 'free'),
            'paid'  => $query->whereIn('billing_type', ['recurring', 'usage']),
            default => $query,
        };
        $applyCategory = fn ($query) => empty($category) ? $query : $query->whereIn('category', $category);

        // Faceted counts (each facet ignores its own selection).
        $cc = $applyPricing($base())->select('category', DB::raw('count(*) as c'))
            ->groupBy('category')->pluck('c', 'category');
        $categoryCounts = [];
        foreach ($catsVocab as $key) {
            $categoryCounts[$key] = (int) ($cc[$key] ?? 0);
        }
        $freeCount = (int) $applyCategory($base())->where('billing_type', 'free')->count();
        $paidCount = (int) $applyCategory($base())->whereIn('billing_type', ['recurring', 'usage'])->count();

        $results = $applyCategory($applyPricing($base()))
            ->when($minRating > 0, fn ($query) => $query->where('rating_avg', '>=', $minRating))
            ->with(['developer:id,name', 'screenshots', 'icon']);
        $results = match ($sort) {
            'name'      => $results->orderBy('name'),
            'top_rated' => $results->orderByDesc('rating_avg')->orderByDesc('rating_count')->orderBy('name'),
            default     => $results->orderByDesc('rating_avg')->orderBy('name'), // featured
        };
        $results = $results->get();

        return view('apps.store.index', [
            'results' => $results,
            'total' => $results->count(),
            'categories' => $catsVocab,
            'categoryCounts' => $categoryCounts,
            'freeCount' => $freeCount,
            'paidCount' => $paidCount,
            'q' => $q,
            'pricing' => $pricing,
            'category' => $category,
            'sort' => $sort,
            'minRating' => $minRating,
            'siteName' => brand_title(),
        ]);
    }

    public function show(string $slug)
    {
        $app = App::where('status', 'approved')
            ->with(['developer:id,name', 'screenshots', 'icon'])
            ->where('slug', $slug)->firstOrFail();

        // Human-readable scopes from the Passport registry; unknown keys render raw.
        $labels = collect(Passport::scopes())->keyBy('id')->map->description;
        $scopes = collect($app->scopes ?? [])->map(fn ($s) => [
            'key' => $s,
            'label' => $labels[$s] ?? $s,
        ])->values();

        $installation = auth()->check() ? AppInstallation::query()
            ->where('app_id', $app->id)->where('user_id', auth()->id())
            ->where('status', 'active')->first() : null;
        $installed = (bool) $installation;

        /* Polish (P5-2 deferral): scopes the app now requests that this install
           has NOT consented to yet — surfaces the re-consent banner. */
        $pendingScopes = $installation
            ? array_values(array_diff($app->scopes ?? [], $installation->granted_scopes ?? []))
            : [];

        $eligibleToReview = MarketplaceReviewController::appEligible($app);
        $myReview = auth()->check()
            ? $app->reviews()->where('user_id', auth()->id())->first()
            : null;

        [$publishedReviews, $distribution] = MarketplaceController::reviewSurface($app);

        return view('apps.store.show', [
            'app' => $app,
            'screenshots' => $app->screenshots,
            'scopes' => $scopes,
            'installed' => $installed,
            'pendingScopes' => $pendingScopes,
            'eligibleToReview' => $eligibleToReview,
            'myReview' => $myReview,
            'publishedReviews' => $publishedReviews,
            'distribution' => $distribution,
            'siteName' => brand_title(),
        ]);
    }
}
