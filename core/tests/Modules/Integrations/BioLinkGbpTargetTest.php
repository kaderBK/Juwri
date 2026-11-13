<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\EntitlementWriter;
use Juwri\Modules\Integrations\Services\Gbp\FakeGbpClient;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * BIOLINK-TPL lane-d (T2-4): the Google Business Profile bio wiring.
 *
 *  (a) GBP posts composer offers the bio URL as the CTA link (helper hidden when
 *      the bio page is dark).
 *  (b) The Google ordering link can point at the storefront (today's behavior) or
 *      the bio page, an owner choice persisted as a StoreOption. A dark bio ALWAYS
 *      falls back to the storefront URL — the regression guard both call sites lean
 *      on (auto-set on location link + manual "Set my ordering link").
 */
class BioLinkGbpTargetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        config(['settings.admin_allow_gbp_link' => true]);
        FakeGbpClient::reset();
        $this->shareLayoutGlobals();
    }

    /** @return array{0: User, 1: Store} */
    private function ownerRestaurant(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$owner, $store];
    }

    private function connectGbp(Store $store, string $loc = 'LOC-1'): void
    {
        Credential::create([
            'store_id' => $store->id, 'feature' => 'gbp', 'provider' => 'gbp', 'active' => true,
            'secrets' => ['access_token' => 'AT', 'location_id' => $loc],
        ]);
    }

    private function goLive(Store $store): void
    {
        config(['settings.admin_allow_biolink' => true]);
        EntitlementWriter::grant($store->user, 'biolink');
        StoreOption::updateStoreOption($store->id, 'bio_settings', json_encode([
            'enabled' => true, 'preset' => 'classic_flat', 'mode' => 'menu_first',
        ]));
        StoreOption::flushOptionMemo();
    }

    /* --------------------------------------------------- (b) settings radio UI */

    public function test_index_hides_the_bio_radio_when_dark(): void
    {
        [$owner, $store] = $this->ownerRestaurant();
        $this->connectGbp($store);
        $this->withoutMiddleware();

        $this->actingAs($owner)->get(route('stores.gbp.index').'?store_id='.$store->id)
            ->assertOk()
            ->assertDontSee('Where should the ordering link go?')
            ->assertDontSee('src=social');
    }

    public function test_index_shows_the_bio_radio_when_live(): void
    {
        [$owner, $store] = $this->ownerRestaurant();
        $this->connectGbp($store);
        $this->goLive($store);
        $this->withoutMiddleware();

        $this->actingAs($owner)->get(route('stores.gbp.index').'?store_id='.$store->id)
            ->assertOk()->assertSee('Where should the ordering link go?')->assertSeeHtml('id="orderingTargetBio"')->assertSeeHtml('value="bio"')->assertSeeHtml('src=social');
    }

    /* ------------------------------------------------------ (b) setLink target */

    public function test_set_link_uses_the_bio_url_when_chosen_and_live(): void
    {
        [$owner, $store] = $this->ownerRestaurant();
        $this->connectGbp($store, 'LOC-1');
        $this->goLive($store);
        $this->withoutMiddleware();

        $this->actingAs($owner)->post(route('stores.gbp.setLink').'?store_id='.$store->id, [
            'ordering_target' => 'bio',
        ])->assertRedirect();

        $expected = route('bio.public', $store->slug).'?src=social';
        $this->assertSame($expected, FakeGbpClient::$links['LOC-1'][0]['uri']);
        // choice persisted for the next auto-set
        $this->assertSame('bio', store_options($store->id, 'gbp_ordering_link_target'));
    }

    public function test_set_link_uses_the_storefront_when_chosen(): void
    {
        [$owner, $store] = $this->ownerRestaurant();
        $this->connectGbp($store, 'LOC-1');
        $this->goLive($store);
        $this->withoutMiddleware();

        $this->actingAs($owner)->post(route('stores.gbp.setLink').'?store_id='.$store->id, [
            'ordering_target' => 'storefront',
        ])->assertRedirect();

        $this->assertSame(effective_public_url($store), FakeGbpClient::$links['LOC-1'][0]['uri']);
    }

    public function test_set_link_falls_back_to_storefront_when_bio_dark(): void
    {
        [$owner, $store] = $this->ownerRestaurant();
        $this->connectGbp($store, 'LOC-1');
        // Owner previously chose bio, but the bio page is NOT live.
        StoreOption::updateStoreOption($store->id, 'gbp_ordering_link_target', 'bio');
        StoreOption::flushOptionMemo();
        $this->withoutMiddleware();

        $this->actingAs($owner)->post(route('stores.gbp.setLink').'?store_id='.$store->id)
            ->assertRedirect();

        // Regression guard: a dark bio must publish the storefront URL, never a
        // dead bio link.
        $this->assertSame(effective_public_url($store), FakeGbpClient::$links['LOC-1'][0]['uri']);
    }

    public function test_set_link_default_is_storefront_today_behavior(): void
    {
        [$owner, $store] = $this->ownerRestaurant();
        $this->connectGbp($store, 'LOC-1');
        $this->goLive($store);
        $this->withoutMiddleware();

        // No ordering_target sent → unchanged from today: storefront URL.
        $this->actingAs($owner)->post(route('stores.gbp.setLink').'?store_id='.$store->id)
            ->assertRedirect();

        $this->assertSame(effective_public_url($store), FakeGbpClient::$links['LOC-1'][0]['uri']);
    }

    /* ----------------------------------------------------- (a) posts composer */

    public function test_posts_composer_hides_the_bio_helper_when_dark(): void
    {
        [$owner, $store] = $this->ownerRestaurant();
        $this->connectGbp($store);
        $this->withoutMiddleware();

        $this->actingAs($owner)->get(route('stores.gbp.posts.index').'?store_id='.$store->id)
            ->assertOk()
            ->assertDontSee('Use my bio page link')
            ->assertDontSee('src=social');
    }

    public function test_posts_composer_shows_the_bio_helper_when_live(): void
    {
        [$owner, $store] = $this->ownerRestaurant();
        $this->connectGbp($store);
        $this->goLive($store);
        $this->withoutMiddleware();

        $this->actingAs($owner)->get(route('stores.gbp.posts.index').'?store_id='.$store->id)
            ->assertOk()->assertSee('Use my bio page link')->assertSeeHtml('src=social');
    }
}
