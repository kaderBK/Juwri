<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Search;

use App\Models\Customer;
use App\Support\SearchTerm;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-101: SearchTerm — literal %/_/\ on every driver (explicit ESCAPE), min/max length, phone prefix
 * matching, unchanged results for ordinary queries, and the per-user throttle on the heavy search routes.
 * Driver-neutral: runs on sqlite, MySQL and PostgreSQL.
 */
class SearchTermTest extends TestCase
{
    use RefreshDatabase;

    private function store(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999], 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 's-'.Str::lower(Str::random(6)),
            'color' => '#111', 'title' => 'R', 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
        ]);
    }

    /** @param list<string> $names */
    private function seedNames(Store $store, array $names, ?string $phone = null): void
    {
        foreach ($names as $name) {
            Customer::create(['store_id' => $store->id, 'name' => $name, 'phone' => $phone ?? '+4477000'.Str::random(6)]);
        }
    }

    /** @return list<string> */
    private function found(Store $store, ?string $term, array $phone = []): array
    {
        $q = Customer::query()->where('store_id', $store->id);

        return SearchTerm::apply($q, ['name'], $term, $phone)->orderBy('name')->pluck('name')->all();
    }

    public function test_clean_trims_collapses_and_enforces_min_and_max(): void
    {
        $this->assertSame('', SearchTerm::clean(null));
        $this->assertSame('', SearchTerm::clean('  a '));
        $this->assertSame('ab', SearchTerm::clean(" ab\n"));
        $this->assertSame('a b', SearchTerm::clean("a \t\n  b"));
        $this->assertSame(SearchTerm::MAX, mb_strlen(SearchTerm::clean(str_repeat('x', 500))));
        $this->assertSame(SearchTerm::MAX, mb_strlen(SearchTerm::clean(str_repeat('é', 500))));
    }

    public function test_normalize_folds_arabic_spelling_variants_and_digits(): void
    {
        $this->assertSame('اكل', SearchTerm::normalize('أكل'));
        $this->assertSame('اكل', SearchTerm::normalize('إكل'));
        $this->assertSame('اكل', SearchTerm::normalize('آكل'));
        $this->assertSame('علي', SearchTerm::normalize('على'));
        $this->assertSame('قهوه', SearchTerm::normalize('قهوة'));
        $this->assertSame('شاورما', SearchTerm::normalize('شَاوِرْمَا')); // tashkeel stripped
        $this->assertSame('شاورما', SearchTerm::normalize('شاـورما'));     // tatweel stripped
        $this->assertSame('burger 3', SearchTerm::normalize('burger ٣'));
        $this->assertSame('2 for 1', SearchTerm::normalize('٢ for ۱'));
        $this->assertSame('Chicken Burger', SearchTerm::normalize('Chicken Burger')); // English untouched
    }

    public function test_escape_makes_wildcards_literal(): void
    {
        $this->assertSame('50!%!_!!', SearchTerm::escape('50%_!'));
        $this->assertSame('%a!%b%', SearchTerm::like('a%b'));
        $this->assertSame('a!%b%', SearchTerm::like('a%b', true));
    }

    public function test_percent_underscore_and_backslash_match_literally(): void
    {
        $store = $this->store();
        $this->seedNames($store, ['100% Juice', 'Apple Pie', 'snake_case', 'snakeXcase', 'back\\slash', 'backXslash']);

        $this->assertSame(['100% Juice'], $this->found($store, '%'.' J'));
        $this->assertSame(['100% Juice'], $this->found($store, '0% '));
        $this->assertSame(['snake_case'], $this->found($store, 'snake_c'));
        $this->assertSame(['back\\slash'], $this->found($store, 'back\\s'));
        // bare wildcards are not "match everything" (and "%%" / "__" are 2 literal chars)
        $this->assertSame([], $this->found($store, '%%'));
        $this->assertSame([], $this->found($store, '__'));
        $this->assertSame([], $this->found($store, '\\\\'));
    }

    public function test_ordinary_queries_keep_their_results_and_stay_case_insensitive(): void
    {
        $store = $this->store();
        $this->seedNames($store, ['Apple Pie', 'Pineapple', 'Banana']);

        $this->assertSame(['Apple Pie', 'Pineapple'], $this->found($store, 'APPLE'));
        $this->assertSame(['Banana'], $this->found($store, 'nan'));
        $this->assertSame(['Apple Pie'], $this->found($store, '  apple   pie '));
    }

    public function test_short_terms_add_no_filter_and_long_terms_are_capped(): void
    {
        $store = $this->store();
        $this->seedNames($store, ['Apple Pie', 'Banana']);

        $this->assertSame(['Apple Pie', 'Banana'], $this->found($store, 'a'));
        $this->assertSame(['Apple Pie', 'Banana'], $this->found($store, null));
        // a 500-char term is cut to 64 chars: the stored name is not matched, no error, no scan blow-up
        $this->assertSame([], $this->found($store, 'Apple Pie'.str_repeat('z', 500)));
        $long = str_repeat('a', 200);
        $this->seedNames($store, [$long]);
        $this->assertSame([$long], $this->found($store, $long)); // prefix of 64 'a's is contained
    }

    public function test_phone_like_terms_match_digit_prefixes_only(): void
    {
        $store = $this->store();
        Customer::create(['store_id' => $store->id, 'name' => 'Alpha', 'phone' => '+447700900123']);
        Customer::create(['store_id' => $store->id, 'name' => 'Bravo', 'phone' => '447700900999']);
        Customer::create(['store_id' => $store->id, 'name' => 'Charlie', 'phone' => '+15550001111']);

        $this->assertSame(['Alpha', 'Bravo'], $this->found($store, '4477009', ['phone']));
        $this->assertSame(['Alpha', 'Bravo'], $this->found($store, '+44 7700 9', ['phone']));
        $this->assertSame(['Charlie'], $this->found($store, '1555', ['phone']));
        Customer::create(['store_id' => $store->id, 'name' => 'Delta', 'phone' => '555-2222']);
        $this->assertSame(['Delta'], $this->found($store, '555-2222', ['phone']));
        // no mid-number scan: "900123" is not a prefix of any stored number
        $this->assertSame([], $this->found($store, '900123', ['phone']));
        // a name-like term never touches the phone column
        $this->assertSame(['Alpha'], $this->found($store, 'alph', ['phone']));
    }

    public function test_search_routes_carry_the_per_user_throttle(): void
    {
        foreach (['dashboard.search', 'marketplace.index', 'apps.store.index'] as $name) {
            $this->assertContains('throttle:search', Route::getRoutes()->getByName($name)->gatherMiddleware(), $name);
        }

        $limit = RateLimiter::limiter('search')(Request::create('/x'));
        $this->assertInstanceOf(Limit::class, $limit);
        $this->assertSame(60, $limit->maxAttempts);
        $this->assertStringStartsWith('search:ip-', $limit->key);
    }

    public function test_the_search_limiter_is_per_user_and_returns_429(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $hit = function (User $user): int {
            $request = Request::create('/dashboard/search', 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.9']);
            $request->setUserResolver(fn () => $user);
            try {
                app(ThrottleRequests::class)->handle($request, fn () => response('ok'), 'search');
            } catch (ThrottleRequestsException) {
                return 429;
            }

            return 200;
        };

        for ($i = 0; $i < 60; $i++) {
            $this->assertSame(200, $hit($a));
        }
        $this->assertSame(429, $hit($a));
        // another user behind the same IP has their own budget
        $this->assertSame(200, $hit($b));
    }

    public function test_dashboard_order_search_treats_wildcards_as_literal(): void
    {
        $store = $this->store();
        $this->withoutMiddleware();
        $this->actingAs($store->user);

        $res = $this->getJson(route('dashboard.search', ['q' => '%%']))->assertOk()->json();
        $orders = collect($res['groups'])->firstWhere('key', 'orders');
        $this->assertTrue($orders === null || $orders['items'] === []);
        $long = $this->getJson(route('dashboard.search', ['q' => str_repeat('q', 5000)]))->assertOk()->json();
        $this->assertLessThanOrEqual(SearchTerm::MAX, mb_strlen($long['query']));
    }

    public function test_filter_mode_never_widens_and_matches_one_char_values(): void
    {
        $store = $this->store();
        Customer::create(['store_id' => $store->id, 'name' => 'A', 'phone' => '+1000001', 'tags' => ['z', 'vip']]);
        Customer::create(['store_id' => $store->id, 'name' => 'B', 'phone' => '+1000002', 'tags' => ['gold']]);
        $names = fn (?string $v) => SearchTerm::applyOrNone(Customer::query()->where('store_id', $store->id), ['tags'], $v)
            ->orderBy('name')->pluck('name')->all();

        $this->assertSame([], $names(''));
        $this->assertSame([], $names('   '));
        $this->assertSame([], $names(null));
        $this->assertSame(['A'], $names('z'));      // a 1-char tag is a real tag
        $this->assertSame(['A'], $names('VIP'));
        $this->assertSame([], $names('%'));         // literal, no match
        $this->assertSame([], $names('_'));
        // free-text apply() keeps its "short term = no filter" contract
        $this->assertSame(2, SearchTerm::apply(Customer::query()->where('store_id', $store->id), ['name'], 'z')->count());
    }

    public function test_integer_and_json_columns_are_searchable_on_every_driver(): void
    {
        $store = $this->store();
        Customer::create(['store_id' => $store->id, 'name' => 'A', 'phone' => '+1000001', 'tags' => ['vip']]);
        $q = fn (array $cols, string $v) => SearchTerm::applyOrNone(Customer::query()->where('name', 'A'), $cols, $v)->count();

        $this->assertSame(1, $q(['store_id'], (string) $store->id)); // integer column (ILIKE needs ::text on pgsql)
        $this->assertSame(1, $q(['tags'], 'vip'));                       // JSON column
        $this->assertSame(1, $q(['name', 'tags', 'store_id'], 'vi'));
        $this->assertSame(0, $q(['store_id'], '999999'));
    }
}
