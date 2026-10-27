<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink;

use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Modules\Biolink\Models\BioBlock;
use Juwri\Modules\Biolink\Services\BioLinkService;
use Tests\TestCase;

/**
 * bio-t15 Lane A / A6: the `giftcard` block (SPEC §2 A6) surfaces the store's
 * EXISTING gift-card feature — never rebuilds it. `mode:'auto'` resolves to
 * the store's real purchase URL only when GiftCardService::enabled() is true
 * for that store; otherwise the block hides (never a dead/fake link).
 * `mode:'url'` keeps the owner-typed destination untouched.
 */
class BioLinkGiftcardBlockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    private function svc(): BioLinkService
    {
        return app(BioLinkService::class);
    }

    private function render(Store $store): string
    {
        $row = $this->svc()->blocksFor($store->id)[0];

        return (string) view('bio._blocks.giftcard', ['block' => $row])->render();
    }

    public function test_auto_mode_links_to_the_real_purchase_page_when_gift_cards_are_live(): void
    {
        $store = Store::factory()->create();
        StoreOption::updateStoreOption($store->id, 'gift_cards_enabled', '1');

        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'giftcard', 'label' => null, 'url' => null,
            'config' => ['mode' => 'auto'],
        ]);

        $html = $this->render($store);

        // T1-6 stamps `src=bio` on links that resolve to this store's own
        // storefront, so match the base URL rather than an exact href="...".
        $this->assertStringContainsString(route('store.giftcards.buy', $store->slug), $html);
        $this->assertStringContainsString('Buy a Gift Card', $html);
    }

    public function test_auto_mode_hides_when_gift_cards_are_not_live(): void
    {
        $store = Store::factory()->create(); // gift_cards_enabled left unset

        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'giftcard', 'url' => null,
            'config' => ['mode' => 'auto'],
        ]);

        $this->assertSame('', trim($this->render($store)));
    }

    public function test_url_mode_keeps_the_owner_typed_destination_regardless_of_feature_state(): void
    {
        $store = Store::factory()->create(); // gift_cards_enabled left unset

        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'giftcard', 'label' => 'Gift Cards', 'url' => null,
            'config' => ['mode' => 'url', 'url' => 'https://external.example/giftcards'],
        ]);

        $html = $this->render($store);
        $this->assertStringContainsString('href="https://external.example/giftcards"', $html);
        $this->assertStringContainsString('Gift Cards', $html);
    }

    public function test_no_url_degrades_to_nothing(): void
    {
        $store = Store::factory()->create();
        BioBlock::factory()->create(['store_id' => $store->id, 'type' => 'giftcard', 'url' => null, 'config' => []]);

        $this->assertSame('', trim($this->render($store)));
    }
}
