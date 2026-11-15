<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\AppStore;

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Theme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Http\Middleware\IsDemoMode;
use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Database\Seeders\ThemeStoreCatalogSeeder;
use Tests\TestCase;

/**
 * Controlling a marketplace listing.
 *
 * Two things an owner of a marketplace needs that the listing editor used
 * not to offer: a deliberate ORDER (the listing was ordered by rating, which
 * is right for a marketplace that wants the best sellers first and useless
 * for an owner with a catalogue in mind) and a way to HIDE a listing without
 * deleting it.
 *
 * These pin both, pin the drag-reorder endpoint and authorization, and pin
 * that the catalogue seeder does not resurrect a theme the owner delisted.
 */
class MarketplaceListingControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([
            AdminMiddleware::class,
            IsDemoMode::class,
            Installed::class,
            PreventRequestForgery::class,
        ]);
        $this->actingAs(User::factory()->create(['status' => 1, 'email_verified_at' => now()]));
    }

    /** A published listing with a published version. */
    private function makeTheme(string $slug, float $rating = 0.0, ?int $sortOrder = null): Theme
    {
        $theme = Theme::create([
            'name' => ucfirst($slug), 'slug' => $slug, 'status' => 'published',
            'engine' => 'liquid', 'price' => 0, 'category' => 'general',
            'tagline' => 'The '.$slug.' theme.', 'rating_avg' => $rating, 'rating_count' => 1,
            'sort_order' => $sortOrder, 'seller_id' => null,
        ]);
        $theme->versions()->create([
            'semver' => '1', 'manifest' => ['name' => $slug, 'version' => '1'],
            'checksum' => hash('sha256', $slug.'@1'), 'status' => 'published',
        ]);

        return $theme;
    }

    public function test_the_owners_order_comes_before_the_rating_order(): void
    {
        $this->makeTheme('glow', 1.0, 2);
        $this->makeTheme('zevoa', 5.0, 1);   // the lower rating, but first by hand
        $this->makeTheme('velora', 3.0);      // no opinion — keeps the rating order

        $res = $this->withSession(['locale' => 'en'])->get(route('marketplace.index'))->assertOk();

        $body = $res->getContent();
        $z = strpos($body, 'Zevoa');
        $g = strpos($body, 'Glow');
        $v = strpos($body, 'Velora');
        $this->assertNotFalse($z);
        $this->assertLessThan($g, $z, 'the hand-ordered theme comes first');
        $this->assertLessThan($v, $g, 'a theme with no position keeps the rating order, after the ones that have one');
    }

    public function test_a_delisted_theme_is_not_in_the_store(): void
    {
        $this->makeTheme('glow');
        $hidden = $this->makeTheme('studio-stay');
        $hidden->update(['status' => 'delisted']);

        $res = $this->withSession(['locale' => 'en'])->get(route('marketplace.index'))->assertOk();

        $res->assertSee('Glow');
        $res->assertDontSee('Studio Stay');
    }

    public function test_the_listing_editor_sets_the_position_and_the_visibility(): void
    {
        $theme = $this->makeTheme('glow');

        $this->post(route('admin.theme-reviews.listing.save', $theme->id), [
            'category' => 'cafe', 'tagline' => 'A new tagline.', 'sort_order' => 7, 'visible' => '1',
        ])->assertRedirect(route('admin.theme-reviews.listing', $theme->id));

        $theme->refresh();
        $this->assertSame(7, (int) $theme->sort_order);
        $this->assertSame('published', $theme->status);

        $this->post(route('admin.theme-reviews.listing.save', $theme->id), [
            'category' => 'cafe', 'tagline' => 'A new tagline.', 'visible' => '0',
        ])->assertRedirect(route('admin.theme-reviews.listing', $theme->id));

        $theme->refresh();
        $this->assertSame('delisted', $theme->status, 'the listing is hidden, not deleted');
    }

    public function test_an_empty_position_returns_the_listing_to_the_rating_order(): void
    {
        $theme = $this->makeTheme('glow', 1.0, 4);

        $this->post(route('admin.theme-reviews.listing.save', $theme->id), [
            'category' => 'general', 'tagline' => 'The glow theme.', 'sort_order' => '', 'visible' => '1',
        ])->assertRedirect(route('admin.theme-reviews.listing', $theme->id));

        $this->assertNull($theme->fresh()->sort_order);
    }

    public function test_dragging_rewrites_the_order_and_leaves_the_tail_unpinned(): void
    {
        $zevoa = $this->makeTheme('zevoa', 5.0, 1);
        $glow = $this->makeTheme('glow', 1.0, 2);
        $velora = $this->makeTheme('velora', 4.0);
        $untouched = $this->makeTheme('aurelia', 2.0, 9);

        $this->postJson(route('admin.theme-reviews.reorder'), [
            'ordered' => [$glow->id, $velora->id],
            'tail' => [$zevoa->id],
        ])->assertOk()->assertJson(['success' => true]);

        $this->assertSame(1, (int) $glow->fresh()->sort_order);
        $this->assertSame(2, (int) $velora->fresh()->sort_order);
        $this->assertNull($zevoa->fresh()->sort_order, 'a listing dragged into the tail follows the rating again');
        $this->assertSame(9, (int) $untouched->fresh()->sort_order, 'a listing the drag did not name stays put');
    }

    public function test_a_drag_that_names_an_unknown_listing_or_names_one_twice_is_refused(): void
    {
        $glow = $this->makeTheme('glow', 1.0, 1);

        $this->postJson(route('admin.theme-reviews.reorder'), [
            'ordered' => [$glow->id, 999999],
            'tail' => [],
        ])->assertStatus(422);

        $this->assertSame(1, (int) $glow->fresh()->sort_order);

        $this->postJson(route('admin.theme-reviews.reorder'), [
            'ordered' => [$glow->id],
            'tail' => [$glow->id],
        ])->assertStatus(422);
    }

    public function test_non_admin_cannot_reorder_or_hide_listings(): void
    {
        $theme = $this->makeTheme('glow', 1.0, 1);

        $this->withMiddleware([AdminMiddleware::class])
            ->postJson(route('admin.theme-reviews.reorder'), ['ordered' => [$theme->id], 'tail' => []])
            ->assertRedirect(route('login'));

        $this->withMiddleware([AdminMiddleware::class])
            ->post(route('admin.theme-reviews.listing.save', $theme->id), ['visible' => '0'])
            ->assertRedirect(route('login'));

        $this->assertSame('published', $theme->fresh()->status);
    }

    public function test_a_logged_in_non_admin_cannot_reorder_or_hide_listings(): void
    {
        $theme = $this->makeTheme('glow', 1.0, 1);
        $before = $theme->fresh()->only(['sort_order', 'status']);

        $merchant = User::factory()->create();

        $this->actingAs($merchant)->withMiddleware([AdminMiddleware::class])
            ->postJson(route('admin.theme-reviews.reorder'), ['ordered' => [$theme->id], 'tail' => []])
            ->assertStatus(302);

        $this->actingAs($merchant)->withMiddleware([AdminMiddleware::class])
            ->post(route('admin.theme-reviews.listing.save', $theme->id), ['visible' => '0'])
            ->assertStatus(302);

        $this->assertSame($before, $theme->fresh()->only(['sort_order', 'status']));
    }

    public function test_the_reviews_page_splits_the_hand_order_from_the_rating_tail(): void
    {
        $settings = new class(['theme_color' => '#111', 'site_title' => 'TestApp', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png', 'currency_sign' => '$', 'currency_pos' => 1, 'site_admin_logo' => 'logo.png'])
        {
            public function __construct(private array $data) {}

            public function __get(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function __isset(string $key): bool
            {
                return true;
            }
        };
        view()->share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'settings' => $settings,
            'languages' => collect(),
            'pages' => collect(),
            'admin_languages' => collect(),
            'notifications' => collect(),
            'totalUnreadNotifications' => 0,
            'totalUnapprovedComments' => 0,
        ]);

        $this->makeTheme('zevoa', 5.0, 1);
        $this->makeTheme('velora', 4.0);

        $html = $this->get(route('admin.theme-reviews.index'))->assertOk()->getContent();

        $order = strpos($html, 'id="listing-order"');
        $tail = strpos($html, 'id="listing-tail"');
        $zevoa = strpos($html, 'Zevoa');
        $velora = strpos($html, 'Velora');
        $this->assertNotFalse($order);
        $this->assertNotFalse($tail);
        $this->assertLessThan($tail, $order);
        $this->assertLessThan($tail, $zevoa, 'a positioned listing is in the drag order');
        $this->assertGreaterThan($tail, $velora, 'a listing with no position stays in the rating tail');
        $this->assertStringContainsString('name="sort_order"', $this->get(route('admin.theme-reviews.listing', Theme::where('slug', 'zevoa')->value('id')))->assertOk()->getContent());
    }

    public function test_the_catalogue_seeder_does_not_resurrect_a_delisted_theme(): void
    {
        $theme = $this->makeTheme('crave');
        $theme->update(['status' => 'delisted']);

        $this->seed(ThemeStoreCatalogSeeder::class);

        $theme->refresh();
        $this->assertSame('delisted', $theme->status, 'a hidden listing stays hidden after a re-seed');
    }
}
