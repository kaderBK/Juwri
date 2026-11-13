<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Models\Story;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Http\Controllers\User\StoryController;
use Tests\Support\TestPlans;
use Tests\TestCase;

class StoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->withoutMiddleware();
    }

    private function makeUser(): User
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(6), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => json_encode(['restaurant_limit' => 999]), 'status' => 1,
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function makeRestaurant(User $owner): Store
    {
        $title = 'R '.Str::random(6);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * WIRING-RUN C1/C3: the admin index shipped with NO Blade view and, worse, with
     * its route registered after the {slug} storefront catch-all — so /stories
     * resolved to publicView and 404'd. Nothing in the suite exercised index(), so
     * both defects stayed invisible.
     *
     * A full HTTP render is not assertable here: the dashboard layout's variables
     * ($activeTheme, $settings) come from a view composer that ViewServiceProvider
     * only registers when APP_INSTALLED is set, and it is deliberately unset in the
     * test env. So this asserts the two things that were actually broken — the URL
     * reaching this controller, and the view existing — plus the data it passes.
     */
    public function test_index_route_reaches_the_controller_and_its_view_exists(): void
    {
        $this->assertTrue(
            view()->exists('owner.marketing.stories'),
            'Missing owner view owner.marketing.stories — stories.index cannot render.'
        );

        $matched = Route::getRoutes()
            ->match(Request::create('/stories', 'GET'));
        $this->assertSame('stories.index', $matched->getName(), '/stories is shadowed by another route.');

        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        Story::create([
            'store_id' => $r->id, 'media_url' => 's1.webp', 'caption' => 'Fresh drop',
            'cta_label' => 'Order now', 'cta_url' => 'https://example.com/menu',
            'active' => true, 'sort' => 1,
        ]);

        $this->actingAs($owner);
        $response = app(StoryController::class)
            ->index(Request::create('/stories', 'GET', ['store_id' => $r->id]));

        $this->assertSame('owner.marketing.stories', $response->name());
        $this->assertSame($r->id, $response->getData()['storeId']);
        $this->assertSame(['Fresh drop'], $response->getData()['stories']->pluck('caption')->all());
    }

    public function test_store_persists_cta_fields(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);

        $this->actingAs($owner)->post(route('stories.store'), [
            'store_id' => $r->id,
            'media_url' => 's1.webp',
            'caption' => 'Fresh drop',
            'cta_label' => 'Order now',
            'cta_url' => 'https://example.com/menu',
            'active' => 1,
        ]);

        $story = Story::where('store_id', $r->id)->first();
        $this->assertNotNull($story);
        $this->assertSame('s1.webp', $story->media_url);
        $this->assertSame('Order now', $story->cta_label);
        $this->assertSame('https://example.com/menu', $story->cta_url);
        $this->assertTrue($story->active);
    }

    public function test_store_requires_media(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);

        $this->actingAs($owner)->post(route('stories.store'), [
            'store_id' => $r->id, 'caption' => 'No media',
        ])->assertSessionHasErrors('media_url');

        $this->assertSame(0, Story::where('store_id', $r->id)->count());
    }

    public function test_public_endpoint_filters_by_window_and_active(): void
    {
        $r = $this->makeRestaurant($this->makeUser());

        // Visible: no bounds, active.
        $open = Story::create(['store_id' => $r->id, 'media_url' => 'open.webp', 'active' => true, 'sort' => 0]);
        // Visible: within window.
        $inWindow = Story::create([
            'store_id' => $r->id, 'media_url' => 'win.webp', 'active' => true, 'sort' => 1,
            'starts_at' => now()->subDay(), 'expires_at' => now()->addDay(),
        ]);
        // Hidden: inactive.
        Story::create(['store_id' => $r->id, 'media_url' => 'off.webp', 'active' => false, 'sort' => 2]);
        // Hidden: expired.
        Story::create([
            'store_id' => $r->id, 'media_url' => 'exp.webp', 'active' => true, 'sort' => 3,
            'starts_at' => now()->subWeek(), 'expires_at' => now()->subDay(),
        ]);
        // Hidden: not started yet.
        Story::create([
            'store_id' => $r->id, 'media_url' => 'future.webp', 'active' => true, 'sort' => 4,
            'starts_at' => now()->addDay(),
        ]);

        $res = $this->getJson(route('stories.public', $r->id))->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$open->id, $inWindow->id], $ids);
    }

    public function test_public_endpoint_orders_by_sort_and_returns_cta(): void
    {
        $r = $this->makeRestaurant($this->makeUser());

        Story::create(['store_id' => $r->id, 'media_url' => 'b.webp', 'active' => true, 'sort' => 2, 'caption' => 'B']);
        Story::create([
            'store_id' => $r->id, 'media_url' => 'a.webp', 'active' => true, 'sort' => 1,
            'caption' => 'A', 'cta_label' => 'Tap', 'cta_url' => 'https://example.com/a',
        ]);
        Story::create(['store_id' => $r->id, 'media_url' => 'c.webp', 'active' => true, 'sort' => 3, 'caption' => 'C']);

        $data = $this->getJson(route('stories.public', $r->id))->assertOk()->json('data');

        $this->assertSame(['A', 'B', 'C'], collect($data)->pluck('caption')->all());
        $this->assertSame('Tap', $data[0]['cta_label']);
        $this->assertSame('https://example.com/a', $data[0]['cta_url']);
    }

    /**
     * WIRING-RUN C3: the admin Delete button is behind a native confirm(), which
     * blocks the browser-automation harness — so the destroy path is asserted here
     * rather than left unverified. Also pins tenant isolation on the same route.
     */
    public function test_destroy_removes_own_story_and_refuses_another_owners(): void
    {
        $owner = $this->makeUser();
        $r = $this->makeRestaurant($owner);
        $mine = Story::create(['store_id' => $r->id, 'media_url' => 's1.webp', 'active' => true, 'sort' => 1]);

        $this->actingAs($owner)->delete(route('stories.destroy', $mine->id));
        $this->assertDatabaseMissing('stories', ['id' => $mine->id]);

        $stranger = $this->makeUser();
        $theirs = Story::create([
            'store_id' => $this->makeRestaurant($stranger)->id,
            'media_url' => 's2.webp', 'active' => true, 'sort' => 1,
        ]);

        $this->actingAs($owner)->delete(route('stories.destroy', $theirs->id))->assertForbidden();
        $this->assertDatabaseHas('stories', ['id' => $theirs->id]);
    }

    public function test_tenant_isolation(): void
    {
        $owner = $this->makeUser();
        $otherRestaurant = $this->makeRestaurant($this->makeUser());
        $otherStory = Story::create(['store_id' => $otherRestaurant->id, 'media_url' => 'x.webp', 'sort' => 0]);

        $this->actingAs($owner)->post(route('stories.store'), [
            'store_id' => $otherRestaurant->id, 'media_url' => 'hack.webp',
        ])->assertForbidden();

        $this->actingAs($owner)->deleteJson(route('stories.destroy', $otherStory->id))->assertForbidden();
        $this->assertDatabaseHas('stories', ['id' => $otherStory->id]);
    }
}
