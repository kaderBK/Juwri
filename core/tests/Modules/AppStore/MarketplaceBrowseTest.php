<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\AppStore;

use App\Models\Theme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * stores P1-5: the public Theme Store browse — only published themes with a
 * published version are listed; faceted price/industry filters with counts that
 * ignore their own selection; zero-hit values reported as 0; search + sort.
 */
class MarketplaceBrowseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            \App\Http\Middleware\Installed::class,
        ]);
    }

    private function theme(string $slug, string $cat, float $price, ?string $name = null, ?string $tagline = null, bool $publishVersion = true, string $status = 'published'): Theme
    {
        $t = Theme::create([
            'name' => $name ?? ucfirst($slug), 'slug' => $slug, 'status' => $status,
            'price' => $price, 'category' => $cat, 'tagline' => $tagline,
        ]);
        $t->versions()->create([
            'semver' => '1.0.0', 'manifest' => ['t' => $slug], 'checksum' => hash('sha256', $slug),
            'status' => $publishVersion ? 'published' : 'review',
        ]);

        return $t;
    }

    private function seedCatalog(): void
    {
        $this->theme('free-cafe', 'cafe', 0);
        $this->theme('paid-pizza', 'pizza', 19);
        $this->theme('paid-pizza2', 'pizza', 25);
        $this->theme('paid-bakery', 'bakery', 15);
    }

    public function test_lists_only_published_themes_with_a_published_version(): void
    {
        $this->theme('ok', 'cafe', 0);                                   // listed
        $this->theme('no-version', 'cafe', 0, publishVersion: false);    // version still in review → hidden
        $this->theme('draft', 'cafe', 0, status: 'draft');               // theme not published → hidden

        $slugs = $this->get(route('marketplace.index'))->viewData('results')->pluck('slug')->all();
        $this->assertSame(['ok'], $slugs);
    }

    public function test_price_facet_filters_and_counts(): void
    {
        $this->seedCatalog();
        $res = $this->get(route('marketplace.index'));
        $this->assertSame(1, $res->viewData('freeCount'));
        $this->assertSame(3, $res->viewData('paidCount'));

        $paid = $this->get(route('marketplace.index', ['price' => 'paid']));
        $this->assertEqualsCanonicalizing(['paid-pizza', 'paid-pizza2', 'paid-bakery'], $paid->viewData('results')->pluck('slug')->all());

        $free = $this->get(route('marketplace.index', ['price' => 'free']));
        $this->assertSame(['free-cafe'], $free->viewData('results')->pluck('slug')->all());
    }

    public function test_industry_facet_filters_and_counts(): void
    {
        $this->seedCatalog();
        $res = $this->get(route('marketplace.index'));
        $counts = $res->viewData('industryCounts');
        $this->assertSame(2, $counts['pizza']);
        $this->assertSame(1, $counts['bakery']);
        $this->assertSame(1, $counts['cafe']);
        $this->assertSame(0, $counts['fine_dining']); // zero-hit → disabled in the UI

        $pizza = $this->get(route('marketplace.index', ['industry' => ['pizza']]));
        $this->assertEqualsCanonicalizing(['paid-pizza', 'paid-pizza2'], $pizza->viewData('results')->pluck('slug')->all());
    }

    public function test_price_facet_counts_ignore_price_but_respect_industry(): void
    {
        $this->seedCatalog();
        // With industry=cafe active, only the free cafe exists → paid facet count 0.
        $res = $this->get(route('marketplace.index', ['industry' => ['cafe']]));
        $this->assertSame(1, $res->viewData('freeCount'));
        $this->assertSame(0, $res->viewData('paidCount'));
    }

    public function test_industry_counts_respect_price_selection(): void
    {
        $this->seedCatalog();
        // Under price=free, only the cafe qualifies → pizza/bakery counts drop to 0.
        $res = $this->get(route('marketplace.index', ['price' => 'free']));
        $counts = $res->viewData('industryCounts');
        $this->assertSame(1, $counts['cafe']);
        $this->assertSame(0, $counts['pizza']);
        $this->assertSame(0, $counts['bakery']);
    }

    public function test_combined_price_and_industry(): void
    {
        $this->seedCatalog();
        $res = $this->get(route('marketplace.index', ['price' => 'paid', 'industry' => ['pizza']]));
        $this->assertEqualsCanonicalizing(['paid-pizza', 'paid-pizza2'], $res->viewData('results')->pluck('slug')->all());
    }

    public function test_search_matches_name_and_tagline(): void
    {
        $this->theme('zephyr', 'general', 0, name: 'Zephyr', tagline: 'coastal breeze');
        $this->theme('other', 'general', 0, name: 'Other');

        $byName = $this->get(route('marketplace.index', ['q' => 'zeph']));
        $this->assertSame(['zephyr'], $byName->viewData('results')->pluck('slug')->all());

        $byTagline = $this->get(route('marketplace.index', ['q' => 'coastal']));
        $this->assertSame(['zephyr'], $byTagline->viewData('results')->pluck('slug')->all());
    }

    public function test_sort_by_price(): void
    {
        $this->seedCatalog();
        $low = $this->get(route('marketplace.index', ['sort' => 'price_low']))->viewData('results')->pluck('price')->map(fn ($p) => (float) $p)->all();
        $this->assertSame($low, collect($low)->sort()->values()->all());

        $high = $this->get(route('marketplace.index', ['sort' => 'price_high']))->viewData('results')->pluck('price')->map(fn ($p) => (float) $p)->all();
        $this->assertSame($high, collect($high)->sortDesc()->values()->all());
    }

    public function test_ignores_unknown_industry_param(): void
    {
        $this->seedCatalog();
        // A bogus industry key must not filter to empty — it's dropped, not applied.
        $res = $this->get(route('marketplace.index', ['industry' => ['not_real']]));
        $this->assertSame(4, $res->viewData('total'));
    }
}
