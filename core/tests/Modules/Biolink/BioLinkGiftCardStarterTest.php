<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink;

use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Biolink\Models\BioBlock;
use Juwri\Modules\Biolink\Services\BioLinkService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * BIOLINK-TPL T3-4 (A9): archetypes seed a `link` block pointing at the gift-card
 * buy page when the store has gift cards enabled, and seed it hidden (active=0)
 * otherwise. No new block type — the 14 stay frozen.
 */
class BioLinkGiftCardStarterTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): BioLinkService
    {
        return app(BioLinkService::class);
    }

    /** A store whose owner is on a plan (so gift_cards entitlement resolves). */
    private function store(bool $giftCardsOn): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'status' => 1, 'settings' => ['restaurant_limit' => 999],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1',
        ]);
        if ($giftCardsOn) {
            StoreOption::updateStoreOption($store->id, 'gift_cards_enabled', '1');
            StoreOption::flushOptionMemo();
        }

        return $store;
    }

    private function giftLink(int $storeId): ?BioBlock
    {
        // restaurant_essential seeds exactly one link block (the gift card link).
        return BioBlock::where('store_id', $storeId)->where('type', 'link')->first();
    }

    public function test_gift_card_link_is_active_and_points_at_the_buy_page_when_enabled(): void
    {
        $store = $this->store(giftCardsOn: true);

        $this->svc()->applyStarter($store->id, 'classic_flat', false); // restaurant_essential

        $link = $this->giftLink($store->id);
        $this->assertNotNull($link);
        $this->assertSame('link', $link->type);            // no new block type
        $this->assertTrue((bool) $link->active);
        $this->assertStringContainsString('/gift-cards', (string) $link->url);
        $this->assertStringContainsString($store->slug, (string) $link->url);
    }

    public function test_gift_card_link_is_seeded_visible_without_a_fake_url_when_disabled(): void
    {
        $store = $this->store(giftCardsOn: false);

        $this->svc()->applyStarter($store->id, 'classic_flat', false);

        // Choosing a design shows the full layout: the gift-card block is seeded
        // ACTIVE (visible, ready to point somewhere or remove) rather than hidden —
        // but we never fabricate a buy-page URL the store cannot serve.
        $link = $this->giftLink($store->id);
        $this->assertNotNull($link);
        $this->assertTrue((bool) $link->active);
        $this->assertNull($link->url);                     // no fake buy-page link — owner fills it in
    }
}
