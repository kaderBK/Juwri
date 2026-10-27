<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\AppStore;

use App\Models\App;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * stores P2-1: the public App Store browse — approved apps only, faceted
 * category/pricing filters with counts that ignore their own selection, search.
 */
class AppStoreBrowseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([\App\Http\Middleware\Installed::class]);
    }

    private function app(string $slug, string $cat, string $billing = 'free', string $status = 'approved', ?string $name = null, ?string $tagline = null): App
    {
        return App::create([
            'name' => $name ?? ucfirst($slug), 'slug' => $slug, 'scopes' => ['menu:read'],
            'status' => $status, 'category' => $cat, 'tagline' => $tagline,
            'billing_type' => $billing, 'price' => $billing === 'recurring' ? 9 : 0,
            'billing_period_days' => 30,
            'usage_unit' => $billing === 'usage' ? 'call' : null,
            'usage_price' => $billing === 'usage' ? 0.05 : null,
        ]);
    }

    private function seedCatalog(): void
    {
        $this->app('order-free', 'ordering', 'free');
        $this->app('mkt-paid', 'marketing', 'recurring');
        $this->app('mkt-usage', 'marketing', 'usage');
        $this->app('deliver-paid', 'delivery', 'recurring');
    }

    public function test_lists_only_approved_apps(): void
    {
        $this->app('ok', 'ordering');
        $this->app('draft', 'ordering', status: 'draft');
        $this->app('review', 'ordering', status: 'review');
        $this->app('rejected', 'ordering', status: 'rejected');

        $slugs = $this->get(route('apps.store.index'))->viewData('results')->pluck('slug')->all();
        $this->assertSame(['ok'], $slugs);
    }

    public function test_category_facet_filters_and_counts(): void
    {
        $this->seedCatalog();
        $res = $this->get(route('apps.store.index'));
        $counts = $res->viewData('categoryCounts');
        $this->assertSame(1, $counts['ordering']);
        $this->assertSame(2, $counts['marketing']);
        $this->assertSame(1, $counts['delivery']);
        $this->assertSame(0, $counts['pos']); // zero-hit → disabled

        $mkt = $this->get(route('apps.store.index', ['category' => ['marketing']]));
        $this->assertEqualsCanonicalizing(['mkt-paid', 'mkt-usage'], $mkt->viewData('results')->pluck('slug')->all());
    }

    public function test_pricing_facet_filters_and_counts(): void
    {
        $this->seedCatalog();
        $res = $this->get(route('apps.store.index'));
        $this->assertSame(1, $res->viewData('freeCount'));  // only order-free
        $this->assertSame(3, $res->viewData('paidCount'));  // recurring + usage + recurring

        $free = $this->get(route('apps.store.index', ['pricing' => 'free']));
        $this->assertSame(['order-free'], $free->viewData('results')->pluck('slug')->all());

        $paid = $this->get(route('apps.store.index', ['pricing' => 'paid']));
        $this->assertEqualsCanonicalizing(['mkt-paid', 'mkt-usage', 'deliver-paid'], $paid->viewData('results')->pluck('slug')->all());
    }

    public function test_category_counts_respect_pricing_selection(): void
    {
        $this->seedCatalog();
        // Under pricing=free only the ordering app qualifies.
        $res = $this->get(route('apps.store.index', ['pricing' => 'free']));
        $counts = $res->viewData('categoryCounts');
        $this->assertSame(1, $counts['ordering']);
        $this->assertSame(0, $counts['marketing']);
        $this->assertSame(0, $counts['delivery']);
    }

    public function test_pricing_counts_respect_category_selection(): void
    {
        $this->seedCatalog();
        // Under category=marketing: one recurring + one usage → free 0, paid 2.
        $res = $this->get(route('apps.store.index', ['category' => ['marketing']]));
        $this->assertSame(0, $res->viewData('freeCount'));
        $this->assertSame(2, $res->viewData('paidCount'));
    }

    public function test_search_matches_name_and_tagline(): void
    {
        $this->app('zeta', 'ordering', name: 'Zeta', tagline: 'coastal sync');
        $this->app('omega', 'ordering', name: 'Omega');

        $this->assertSame(['zeta'], $this->get(route('apps.store.index', ['q' => 'zeta']))->viewData('results')->pluck('slug')->all());
        $this->assertSame(['zeta'], $this->get(route('apps.store.index', ['q' => 'coastal']))->viewData('results')->pluck('slug')->all());
    }

    public function test_ignores_unknown_category_param(): void
    {
        $this->seedCatalog();
        $res = $this->get(route('apps.store.index', ['category' => ['not_real']]));
        $this->assertSame(4, $res->viewData('total'));
    }
}
