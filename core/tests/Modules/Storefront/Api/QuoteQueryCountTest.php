<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\Api;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Contracts\QuoteItems;
use Juwri\Modules\Ordering\Contracts\StorefrontOrdering;
use Juwri\Modules\Ordering\Data\StorefrontQuote;
use Tests\Support\StorefrontApi\ApiFixtures;
use Tests\TestCase;

/**
 * Load-test follow-up (LOAD-TEST.md item 4): a cart quote loads each item once, with its choice groups and add-ons,
 * for the whole quote — the query count no longer grows with the cart (before: 49 / 115 / 214 queries for 1 / 3 / 6
 * lines; now 24 for each, of which 12 are the plan / entitlement checks of the request). And the cache changes no
 * number: the quote priced inside the cache scope equals the one priced with fresh queries.
 */
class QuoteQueryCountTest extends TestCase
{
    use RefreshDatabase;

    private const BUDGET = 25; // p5r: +1 branch_hour_exceptions read (P6-291 open check)

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        app()->setLocale('en');
        $this->travelTo(Carbon::parse('2026-10-02 18:40:00', 'UTC'));
        $this->store = (new ApiFixtures)->saffron();
    }

    /** @return list<array<string, mixed>> */
    private function lines(int $n): array
    {
        $lines = [];
        for ($i = 0; $i < $n; $i++) {
            $lines[] = $i % 2 === 0
                ? ['line_id' => 'l'.$i, 'item_id' => ApiFixtures::GRILL, 'qty' => $i + 1, 'option_ids' => [ApiFixtures::OPT_FOR_ONE, ApiFixtures::OPT_GARLIC]]
                : ['line_id' => 'l'.$i, 'item_id' => ApiFixtures::TAWOOK, 'qty' => 1];
        }

        return $lines;
    }

    private function queries(int $n): int
    {
        $body = ['order_type' => 'takeaway', 'lines' => $this->lines($n)];
        $headers = ['X-Storefront-Token' => 'sfx_demo_saffron_read_0001'];
        $this->postJson('/api/v1/storefront/quote', $body, $headers)->assertOk(); // warm the per-process caches
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->postJson('/api/v1/storefront/quote', $body, $headers)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_the_quote_query_count_is_flat_as_the_cart_grows(): void
    {
        $counts = array_map(fn (int $n) => $this->queries($n), [1, 3, 6, 12]);
        $this->assertLessThanOrEqual(self::BUDGET, $counts[1], 'queries for a 3-line cart: '.json_encode($counts));
        $this->assertSame([$counts[0]], array_values(array_unique($counts)), 'the query count grows with the lines: '.json_encode($counts));
        $this->assertFalse(app(QuoteItems::class)->active(), 'the cache scope is closed after the quote');
    }

    public function test_the_cache_changes_no_number_of_the_quote(): void
    {
        $ordering = app(StorefrontOrdering::class);
        $lines = array_map(fn (array $l) => ['item' => $l['item_id'], 'qty' => $l['qty'], 'options' => $l['option_ids'] ?? [], 'variant' => null, 'note' => null], $this->lines(5));
        $fresh = $ordering->quote($this->store, $lines, 'takeaway');
        $cached = app(QuoteItems::class)->during(fn () => [$ordering->quote($this->store, $lines, 'takeaway'), $ordering->quote($this->store, $lines, 'takeaway')]);

        $numbers = fn (?StorefrontQuote $q) => $q === null ? null : [$q->currency, $q->subtotal, $q->discount, $q->delivery, $q->fee, $q->service, $q->tax, $q->tip, $q->total,
            $q->order->items->map(fn ($i) => [(int) $i->item_id, (int) $i->quantity, json_encode($i->options), $i->itemExtras->map->only(['extra_id', 'price'])->all()])->all()];
        $this->assertNotNull($fresh);
        $this->assertSame($numbers($fresh), $numbers($cached[0]));
        $this->assertSame($numbers($fresh), $numbers($cached[1]), 'a second pass over the cached items prices the same');
    }

    public function test_outside_a_quote_every_lookup_is_fresh_and_store_scoped(): void
    {
        $item = app(QuoteItems::class)->byPublicId((int) $this->store->id, ApiFixtures::GRILL);
        $this->assertNotNull($item);
        $this->assertNotSame($item, app(QuoteItems::class)->byPublicId((int) $this->store->id, ApiFixtures::GRILL));
        $this->assertNull(app(QuoteItems::class)->byPublicId((int) $this->store->id + 999, ApiFixtures::GRILL));
        app(QuoteItems::class)->during(function () use ($item) {
            app(QuoteItems::class)->prime((int) $this->store->id + 999, [ApiFixtures::GRILL]);
            $this->assertNull(app(QuoteItems::class)->byPublicId((int) $this->store->id + 999, ApiFixtures::GRILL), "another store's lookup");
            $this->assertNull(app(QuoteItems::class)->byId((int) $this->store->id + 999, (int) $item->id));
            $this->assertSame(app(QuoteItems::class)->byId((int) $this->store->id, (int) $item->id), app(QuoteItems::class)->byPublicId((int) $this->store->id, ApiFixtures::GRILL));
        });
    }
}
