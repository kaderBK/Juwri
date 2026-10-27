<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * A3 — the bio-page hook in BioPublicController::show. The bio surface has its
 * OWN independent opt-out (`bio_external_redirect_*`) and is ALWAYS an instant
 * 302 (no interstitial), resolved above the biolink gate. All assertions go
 * HTTP-through-controller against `/{slug}/bio`.
 */
class ExternalRedirectBioHookTest extends TestCase
{
    use RefreshDatabase;

    private const BIO_TARGET = 'https://bio.example.com/{slug}{query}';

    private const MENU_TARGET = 'https://legacy.example.com/{slug}';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    public function test_bio_redirect_302_independent_of_menu_toggle(): void
    {
        [, $store] = $this->makeStore();
        // Bio redirect ON, menu redirect OFF.
        StoreOption::updateStoreOption($store->id, 'bio_external_redirect_enabled', '1');
        StoreOption::updateStoreOption($store->id, 'bio_external_redirect_url', self::BIO_TARGET);

        $response = $this->get(route('bio.public', $store->slug).'?ref=ig');

        $response->assertStatus(302);
        $response->assertHeader('Location', 'https://bio.example.com/'.$store->slug.'?ref=ig');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_menu_keys_do_not_trigger_a_bio_redirect(): void
    {
        [, $store] = $this->makeStore();
        // MENU redirect ON, bio redirect OFF — the bio must NOT read menu keys.
        StoreOption::updateStoreOption($store->id, 'external_redirect_enabled', '1');
        StoreOption::updateStoreOption($store->id, 'external_redirect_url', self::MENU_TARGET);

        $response = $this->get(route('bio.public', $store->slug));

        $this->assertStringNotContainsString('legacy.example.com', (string) $response->headers->get('Location'));
    }

    public function test_bio_redirect_bypassed_for_owner(): void
    {
        [$owner, $store] = $this->makeStore();
        StoreOption::updateStoreOption($store->id, 'bio_external_redirect_enabled', '1');
        StoreOption::updateStoreOption($store->id, 'bio_external_redirect_url', self::BIO_TARGET);

        $response = $this->actingAs($owner)->get(route('bio.public', $store->slug));

        $this->assertStringNotContainsString('bio.example.com', (string) $response->headers->get('Location'));
    }

    public function test_banned_store_bio_not_redirected(): void
    {
        // H1 parity for the bio surface: a banned store (status 0) goes dark.
        [$owner, $store] = $this->makeStore();
        StoreOption::updateStoreOption($store->id, 'bio_external_redirect_enabled', '1');
        StoreOption::updateStoreOption($store->id, 'bio_external_redirect_url', self::BIO_TARGET);
        $owner->status = 0;
        $owner->save();

        $response = $this->get(route('bio.public', $store->slug));

        $this->assertStringNotContainsString('bio.example.com', (string) $response->headers->get('Location'));
    }

    public function test_bio_redirect_ignores_the_menu_interstitial_flag(): void
    {
        // The menu's interstitial key must have NO effect on bio — bio is always
        // an instant 302, never a 200 interstitial page.
        [, $store] = $this->makeStore();
        StoreOption::updateStoreOption($store->id, 'bio_external_redirect_enabled', '1');
        StoreOption::updateStoreOption($store->id, 'bio_external_redirect_url', self::BIO_TARGET);
        StoreOption::updateStoreOption($store->id, 'external_redirect_interstitial', '1');

        $response = $this->get(route('bio.public', $store->slug));

        $response->assertStatus(302);
        $response->assertHeader('Location', 'https://bio.example.com/'.$store->slug);
    }

    public function test_orphaned_store_bio_does_not_500(): void
    {
        // A Post whose owner row was deleted: the bio hook must not dereference a
        // null $store->user (it sits above code that never touched $store->user).
        [$owner, $store] = $this->makeStore();
        User::where('id', $owner->id)->delete();

        $response = $this->get(route('bio.public', $store->slug));

        $this->assertLessThan(500, $response->status());
    }

    /**
     * @return array{0: User, 1: Store}
     */
    private function makeStore(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4),
            'description' => 'T',
            'monthly_price' => 10,
            'annual_price' => 100,
            'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);

        return [$owner, $store];
    }
}
