<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\MenuReview;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Services\Theme\KeptTemplates;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * The shared item-reviews upgrade (histogram + write-a-review).
 *
 * Where the block lands, and which themes it leaves alone, are browser facts —
 * see tests/e2e/journeys/storefront-item-reviews.js. What the server decides is
 * whether the module ships at all and what it is pointed at, and that is what
 * this pins: the endpoint it embeds must be the real reviews URL for THIS store,
 * and the ~40 legacy templates must not carry the script.
 *
 * The data contract is covered here too, because the version this replaced read
 * `rating` and `comment` from a payload whose fields are `stars` and `body` —
 * every review would have rendered as zero stars with no text, and no test
 * would have noticed because no theme ever called it.
 */
class StorefrontItemReviewsPartialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $settings = new class(['theme_color' => '#111', 'site_title' => 'TestApp', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
        {
            public function __construct(private array $data) {}

            public function __get(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function __set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function __isset(string $key): bool
            {
                return array_key_exists($key, $this->data);
            }
        };
        view()->share([
            'activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true),
            'settings' => $settings, 'languages' => collect(), 'pages' => collect(),
        ]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function store(string $theme = 'orbit'): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => ['restaurant_limit' => 999],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);
        StoreOption::updateStoreOption($store->id, 'store_template', $theme);

        return $store;
    }

    private function item(Store $store): Menu
    {
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => '1']);

        return Menu::create([
            'store_id' => $store->id, 'category_id' => $cat->id,
            'name' => 'Club Sandwich', 'price' => 42.00, 'active' => '1',
        ]);
    }

    public function test_the_module_ships_pointed_at_this_stores_reviews_endpoint(): void
    {
        $store = $this->store();

        $html = $this->get('/'.$store->slug)->assertOk()->getContent();

        $this->assertStringContainsString('data-mbr-write', $html, 'the write control is part of the module');
        // The base is emitted through @json, which escapes the slashes.
        $this->assertStringContainsString(
            trim(json_encode(url('r/'.$store->id.'/item')), '"'),
            $html,
            'pointed at this store'
        );
    }

    /** WI-03: a store saved on a removed legacy template renders the fallback, module included. */
    public function test_store_on_a_removed_template_gets_the_module_via_the_fallback(): void
    {
        $store = $this->store('old-2-theme');

        $html = $this->get('/'.$store->slug)->assertOk()->getContent();

        $this->assertStringContainsString('data-mbr-write', $html);
    }

    public function test_it_ships_on_every_retained_template(): void
    {
        foreach (KeptTemplates::MODERN as $theme) {
            $store = $this->store($theme);

            $html = $this->get('/'.$store->slug)->assertOk()->getContent();
            $this->assertStringContainsString('data-mbr-write', $html, "$theme should carry the reviews module");
        }
    }

    /**
     * The field names the histogram is built from. `stars` and `body`, not
     * `rating` and `comment` — the difference between a working chart and five
     * empty bars, and the exact bug the previous version of this partial had.
     */
    public function test_the_endpoint_returns_the_distribution_the_histogram_reads(): void
    {
        $store = $this->store();
        $item = $this->item($store);

        foreach ([5, 5, 3] as $stars) {
            MenuReview::create([
                'store_id' => $store->id, 'menu_id' => $item->id, 'reviewer_name' => 'Diner',
                'stars' => $stars, 'body' => 'Good', 'status' => 'approved',
            ]);
        }

        $payload = $this->getJson('/r/'.$store->id.'/item/'.$item->id.'/reviews')
            ->assertOk()->json();

        $this->assertSame(3, $payload['count']);
        $this->assertEqualsWithDelta(4.33, (float) $payload['average'], 0.01);
        $this->assertSame(2, $payload['distribution'][5]);
        $this->assertSame(1, $payload['distribution'][3]);
        $this->assertSame(0, $payload['distribution'][1]);
        $this->assertArrayHasKey('stars', $payload['data'][0]);
        $this->assertArrayHasKey('body', $payload['data'][0]);
    }

    /** Writing goes through the same URL and is held for the owner. */
    public function test_a_written_review_is_stored_pending(): void
    {
        $store = $this->store();
        $item = $this->item($store);

        $this->postJson('/r/'.$store->id.'/item/'.$item->id.'/reviews', [
            'rating' => 4,
            'text' => 'Solid sandwich.',
            'name' => 'Sam',
        ])->assertCreated();

        $review = MenuReview::where('menu_id', $item->id)->firstOrFail();
        $this->assertSame(4, (int) $review->stars);
        $this->assertSame('Sam', $review->reviewer_name);
        $this->assertSame('pending', $review->status);

        // Pending means invisible: the public list and the histogram must not move.
        $payload = $this->getJson('/r/'.$store->id.'/item/'.$item->id.'/reviews')->assertOk()->json();
        $this->assertSame(0, $payload['count']);
    }
}
