<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

use Juwri\Core\Models\Store;
use App\Models\StoreReview;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DIRECTORY T2-3: the owner dashboard "Reviews" panel — read own approved
 * store_reviews + reply once (editable within 24h). Tenant isolation (house
 * rule) is the load-bearing assertion here: a cross-tenant reply must 403.
 */
class DirectoryOwnerReviewReplyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        // The owner layout composes several globals the test runner has
        // no request for — same stub ReservationTest uses for the same theme.
        $settings = new class(['theme_color' => '#111', 'site_title' => 'Test', 'meta_description' => 'Test', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png']) {
            public function __construct(private array $data) {}
            public function __get(string $key): mixed { return $this->data[$key] ?? null; }
            public function __set(string $key, mixed $value): void { $this->data[$key] = $value; }
            public function __isset(string $key): bool { return array_key_exists($key, $this->data); }
        };
        view()->share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'settings' => $settings,
            'languages' => collect(),
            // ShareErrorsFromSession normally provides this; withoutMiddleware() below skips it.
            'errors' => new \Illuminate\Support\ViewErrorBag(),
        ]);

        $this->withoutMiddleware();
    }

    private function owner(): User
    {
        return User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
    }

    private function store(User $owner): Store
    {
        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-' . Str::lower(Str::random(6)),
            'color' => '#111', 'title' => 'Owner Store',
        ]);
    }

    public function test_owner_lists_only_approved_reviews_for_their_store(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $approved = StoreReview::factory()->approved()->forStore($store->id)->create(['body' => 'Loved it']);
        StoreReview::factory()->forStore($store->id)->create(['body' => 'Still pending']); // must not show

        $this->actingAs($owner)
            ->get(route('dashboard.store-reviews', $store->id))
            ->assertOk()
            ->assertSee('Loved it')
            ->assertDontSee('Still pending');
    }

    public function test_owner_can_reply_once(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $review = StoreReview::factory()->approved()->forStore($store->id)->create();

        $this->actingAs($owner)
            ->post(route('dashboard.store-reviews.reply', [$store->id, $review->id]), ['reply' => 'Thank you!'])
            ->assertRedirect();

        $review->refresh();
        $this->assertSame('Thank you!', $review->owner_reply);
        $this->assertNotNull($review->owner_replied_at);
    }

    public function test_reply_is_editable_within_24_hours_without_resetting_the_clock(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $firstReplyAt = now()->subHours(5);
        $review = StoreReview::factory()->approved()->forStore($store->id)
            ->create(['owner_reply' => 'Initial reply', 'owner_replied_at' => $firstReplyAt]);

        $this->actingAs($owner)
            ->post(route('dashboard.store-reviews.reply', [$store->id, $review->id]), ['reply' => 'Edited reply'])
            ->assertRedirect();

        $review->refresh();
        $this->assertSame('Edited reply', $review->owner_reply);
        $this->assertSame(
            $firstReplyAt->format('Y-m-d H:i:s'),
            $review->owner_replied_at->format('Y-m-d H:i:s'),
            'editing must not push the 24h clock back out'
        );
    }

    public function test_reply_is_locked_after_24_hours(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $review = StoreReview::factory()->approved()->forStore($store->id)
            ->create(['owner_reply' => 'Old reply', 'owner_replied_at' => now()->subHours(25)]);

        $this->actingAs($owner)
            ->post(route('dashboard.store-reviews.reply', [$store->id, $review->id]), ['reply' => 'Too late'])
            ->assertSessionHasErrors('reply');

        $this->assertSame('Old reply', $review->fresh()->owner_reply);
    }

    /** House rule: an owner replying to a review must only reach their OWN store's reviews. */
    public function test_cross_tenant_reply_is_forbidden(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $intruder = $this->owner();
        $review = StoreReview::factory()->approved()->forStore($store->id)->create();

        $this->actingAs($intruder)
            ->post(route('dashboard.store-reviews.reply', [$store->id, $review->id]), ['reply' => 'Not yours'])
            ->assertStatus(403);

        $this->assertNull($review->fresh()->owner_reply);
    }

    public function test_a_pending_review_cannot_be_replied_to(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $review = StoreReview::factory()->forStore($store->id)->create(); // pending

        $this->actingAs($owner)
            ->post(route('dashboard.store-reviews.reply', [$store->id, $review->id]), ['reply' => 'Hi'])
            ->assertNotFound();
    }
}
