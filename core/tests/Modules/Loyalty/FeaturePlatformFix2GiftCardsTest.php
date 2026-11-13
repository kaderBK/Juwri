<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use App\Http\Middleware\AbuseHoldWall;
use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\GiftCard;
use App\Services\Entitlements;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use App\Services\GiftCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\SyncDashboardStore;
use Juwri\Core\Models\Feature;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\EntitlementWriter;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\Support\EditionTestHooks;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * F2-4 (feature-platform gap fix): `gift_cards` reads its entitlement, at all
 * FOUR entry points — including the two side doors the audit named.
 *
 * Before: only the customer-facing GET carried `feature:gift_cards` (kill
 * switch only). The POST that actually takes the buyer's money carried NO gate
 * at all, and the entire owner admin group (issue / void / adjust — all of
 * which move real stored value) carried none either. The $1.99/mo SKU bought
 * nothing in either direction.
 *
 * Two subjects, deliberately:
 *   • storefront (buy page + purchase POST) — the buyer is a guest, so the
 *     subject is the STORE OWNER, resolved in GiftCardService::enabled().
 *     Unsold → 404: a storefront hides what it cannot sell.
 *   • owner dashboard (admin group) — real session, so `feature:gift_cards`
 *     middleware is correct and gives the paywall redirect.
 * Killed is 403 everywhere: platform OFF is an outage, not a missing page.
 *
 * REDEMPTION of an already-issued card is deliberately never gated — see the
 * docblock on GiftCardService::enabled().
 */
class FeaturePlatformFix2GiftCardsTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'gift_cards';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        // APP_INSTALLED is unset under PHPUnit, so ViewServiceProvider never shares
        // the theme/settings bag and any full page render 500s. House pattern
        // (MenuOperationsTest et al): share it here so a 200 means a 200.
        $settings = new class(['theme_color' => '#111', 'site_title' => 'T', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
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
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect(), 'pages' => collect()]);

        // Keep the feature middleware (it IS the admin-group gate under test);
        // drop only install/csrf/auth plumbing, like FeatureGateAuditTest.
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
            SyncDashboardStore::class,
            ...EditionTestHooks::middleware(),
            AbuseHoldWall::class,
        ]);

        // FeatureAccess memoizes store->allowed in a static that outlives a single
        // test method; without this a store id reused after RefreshDatabase would
        // inherit the previous test's verdict.
        FeatureAccess::flush();

        $this->killSwitch(true);
        $this->planGate(true);
    }

    // ------------------------------------------------------------- fixtures

    private function killSwitch(bool $on): void
    {
        config(['settings.admin_allow_'.self::KEY => $on ? 1 : 0]);
        FeatureRegistry::clearCache();
    }

    /** Self-sufficient: does not depend on the sibling gate-flip seeder. */
    private function planGate(bool $on): void
    {
        Feature::updateOrCreate(['key' => self::KEY], ['label' => 'Gift Cards', 'plan_gated' => $on]);
        app()->forgetInstance('features.plan_gated');
        FeatureAccess::flush();
    }

    /** @return array{0:Store,1:User} a store with gift cards switched on, and its owner. */
    private function store(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(6), 'description' => 'T', 'monthly_price' => 1,
            'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create([
            'group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(),
        ]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreSettings::set($store->id, 'gift_cards_enabled', '1');

        return [$store, $owner];
    }

    private function entitle(User $owner, string $sourceType = 'admin', string $reason = 'test-fixture'): void
    {
        EntitlementWriter::grant($owner, self::KEY, $sourceType, null, '1', null, $reason);
        app()->forgetInstance('features.plan_gated');
        FeatureAccess::flush();
    }

    private function buyPage(Store $store)
    {
        return $this->get(route('store.giftcards.buy', $store->id));
    }

    /** The money path. A refusal must happen BEFORE validation, so send junk. */
    private function purchase(Store $store)
    {
        return $this->post(route('store.giftcards.purchase', $store->id), [
            'amount' => 25, 'payment_method' => 1,
        ]);
    }

    // ----------------------------------------------------------- unentitled

    public function test_an_unentitled_store_hides_the_buy_page(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        [$store, $owner] = $this->store();

        $this->assertFalse(
            Entitlements::allows($owner->fresh(), self::KEY),
            'the fixture owner must start unentitled'
        );
        $this->assertFalse(GiftCardService::enabled($store->id));
        $this->buyPage($store)->assertNotFound();
    }

    /** SIDE DOOR 1: the POST that takes money carried no gate whatsoever. */
    public function test_an_unentitled_store_refuses_the_purchase_post(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        [$store] = $this->store();

        $this->purchase($store)->assertNotFound();
        $this->assertSame(0, Transaction::count(), 'no money row may be created');
    }

    /** SIDE DOOR 2: the whole owner admin group (issue / void / adjust) was ungated. */
    public function test_an_unentitled_owner_cannot_reach_the_gift_card_admin(): void
    {
        $this->requiresEditionPackage('a plan limit or entitlement of an edition package');
        [$store, $owner] = $this->store();

        $index = $this->actingAs($owner)->get(route('stores.giftcards.index'));
        $this->assertContains(
            $index->getStatusCode(),
            [302, 403],
            'unentitled owner must be paywalled (redirect) or refused, got '.$index->getStatusCode()
        );

        $this->actingAs($owner)
            ->postJson(route('stores.giftcards.issue'), ['amount' => 50])
            ->assertStatus(403);
        $this->assertSame(0, GiftCard::count(), 'no stored value may be issued by an unentitled owner');
    }

    // ------------------------------------------------------------- entitled

    public function test_an_entitled_store_can_sell_and_administer_gift_cards(): void
    {
        [$store, $owner] = $this->store();
        $this->entitle($owner);

        $this->assertTrue(GiftCardService::enabled($store->id));
        $this->buyPage($store)->assertOk();
        $this->actingAs($owner)->get(route('stores.giftcards.index'))->assertOk();
    }

    // -------------------------------------------------------- grandfathered

    public function test_a_grandfathered_owner_keeps_gift_cards(): void
    {
        [$store, $owner] = $this->store();
        // Exactly what `entitlements:grandfather --apply` writes: non-expiring.
        $this->entitle($owner, 'grandfather', 'grandfather-2026-07');

        $this->assertTrue(GiftCardService::enabled($store->id));
        $this->buyPage($store)->assertOk();
        $this->actingAs($owner)->get(route('stores.giftcards.index'))->assertOk();
    }

    // --------------------------------------------------------------- killed

    public function test_the_kill_switch_wins_over_entitlement(): void
    {
        [$store, $owner] = $this->store();
        $this->entitle($owner);
        $this->killSwitch(false);

        $this->buyPage($store)->assertStatus(403);
        $this->purchase($store)->assertStatus(403);
        $this->actingAs($owner)->get(route('stores.giftcards.index'))->assertStatus(403);
        $this->actingAs($owner)
            ->postJson(route('stores.giftcards.issue'), ['amount' => 50])
            ->assertStatus(403);
    }

    // -------------------------------------------------------------- ungated

    public function test_an_ungated_key_keeps_kill_only_behavior(): void
    {
        // Installs that have not run the gate-flip seeder must behave exactly as
        // before: the per-store toggle alone decides on the storefront.
        $this->planGate(false);
        [$store, $owner] = $this->store();

        $this->assertTrue(GiftCardService::enabled($store->id));
        $this->buyPage($store)->assertOk();
        $this->actingAs($owner)->get(route('stores.giftcards.index'))->assertOk();
    }
}
