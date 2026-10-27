<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink;

use App\Models\LoyaltyProgram;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\EntitlementWriter;
use Juwri\Modules\Loyalty\Services\GoogleWallet\GiftCardPassMapper;
use Juwri\Modules\Loyalty\Services\GoogleWallet\LoyaltyPassMapper;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * BIOLINK-TPL lane-d (T3-1): the bio-page `linksModuleData` on Google Wallet
 * loyalty + gift-card passes, and its per-store opt-in toggle.
 *
 * Regression guard first: with the toggle off, OR the bio page dark, the pass
 * class carries NO links module — byte-identical to before this run. Only a
 * store that opted in AND has a live bio page gets a `?src=share` bio link.
 */
class BioLinkWalletLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config([
            'services.google_wallet.issuer_id' => '3388000000012345678',
        ]);
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
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(8)), 'color' => '#112233',
            'title' => 'Bistro', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1',
        ]);

        return [$owner, $store];
    }

    private function program(Store $store): LoyaltyProgram
    {
        return LoyaltyProgram::create([
            'store_id' => $store->id, 'type' => 'points', 'earn_rate' => 1,
            'reward_threshold' => 100, 'reward_description' => 'Free side', 'reward_discount' => 5.0, 'active' => true,
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

    private function optIn(Store $store): void
    {
        StoreOption::updateStoreOption($store->id, 'wallet_bio_link', '1');
        StoreOption::flushOptionMemo();
    }

    /* --------------------------------------------------------- mapper payloads */

    public function test_loyalty_class_carries_the_bio_link_when_live_and_opted_in(): void
    {
        [, $store] = $this->ownerRestaurant();
        $this->goLive($store);
        $this->optIn($store);

        $class = LoyaltyPassMapper::class($store, $this->program($store));

        $expected = route('bio.public', $store->slug).'?src=share';
        $this->assertSame($expected, $class['linksModuleData']['uris'][0]['uri']);
        $this->assertSame('bio', $class['linksModuleData']['uris'][0]['id']);
    }

    public function test_giftcard_class_carries_the_bio_link_when_live_and_opted_in(): void
    {
        [, $store] = $this->ownerRestaurant();
        $this->goLive($store);
        $this->optIn($store);

        $class = GiftCardPassMapper::class($store);

        $expected = route('bio.public', $store->slug).'?src=share';
        $this->assertSame($expected, $class['linksModuleData']['uris'][0]['uri']);
    }

    public function test_no_link_when_not_opted_in_even_if_bio_live(): void
    {
        [, $store] = $this->ownerRestaurant();
        $this->goLive($store);
        // toggle left OFF

        $this->assertArrayNotHasKey('linksModuleData', LoyaltyPassMapper::class($store, $this->program($store)));
        $this->assertArrayNotHasKey('linksModuleData', GiftCardPassMapper::class($store));
    }

    public function test_no_link_when_bio_dark_even_if_opted_in(): void
    {
        [, $store] = $this->ownerRestaurant();
        $this->optIn($store); // opted in, but bio never went live

        // Regression guard: a dark bio must leave the pass class link-free.
        $this->assertArrayNotHasKey('linksModuleData', LoyaltyPassMapper::class($store, $this->program($store)));
        $this->assertArrayNotHasKey('linksModuleData', GiftCardPassMapper::class($store));
    }

    /* ----------------------------------------------------------- toggle (HTTP) */

    public function test_toggle_persists_the_wallet_bio_link_option(): void
    {
        [$owner, $store] = $this->ownerRestaurant();
        $this->withoutMiddleware();

        $this->actingAs($owner)->post(route('googleWallet.toggle', $store->id), [
            'google_wallet_enabled' => '1',
            'wallet_bio_link' => '1',
        ])->assertRedirect();

        $this->assertSame('1', (string) store_options($store->id, 'wallet_bio_link'));

        // Unchecking clears it.
        $this->actingAs($owner)->post(route('googleWallet.toggle', $store->id), [
            'google_wallet_enabled' => '1',
        ])->assertRedirect();

        StoreOption::flushOptionMemo();
        $this->assertSame('0', (string) store_options($store->id, 'wallet_bio_link'));
    }

    public function test_toggle_is_owner_scoped(): void
    {
        [$owner] = $this->ownerRestaurant();
        [, $foreign] = $this->ownerRestaurant();
        $this->withoutMiddleware();

        $this->actingAs($owner)->post(route('googleWallet.toggle', $foreign->id), [
            'wallet_bio_link' => '1',
        ])->assertNotFound();
    }
}
