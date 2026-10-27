<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink;

use App\Models\LoyaltyProgram;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Modules\Biolink\Models\BioBlock;
use Juwri\Modules\Biolink\Services\BioLinkService;
use Tests\TestCase;

/**
 * bio-t15 Lane A / A7: the `loyalty` block (SPEC §2 A7). No anonymous
 * single-URL enroll/wallet-save destination exists on this platform (LEDGER
 * "Contract changes"), so `mode:'auto'` links to the store's own storefront
 * only when an active LoyaltyProgram exists AND the `loyalty` feature is
 * allowed for the store — else it hides. `mode:'url'` is untouched.
 */
class BioLinkLoyaltyBlockTest extends TestCase
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

        return (string) view('bio._blocks.loyalty', ['block' => $row])->render();
    }

    public function test_auto_mode_links_to_the_storefront_when_an_active_program_exists(): void
    {
        $store = Store::factory()->create();
        LoyaltyProgram::create([
            'store_id' => $store->id, 'active' => true, 'type' => 'stamps',
            'earn_rate' => 1, 'reward_threshold' => 10, 'reward_description' => 'Free item',
        ]);

        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'loyalty', 'label' => null, 'url' => null,
            'config' => ['mode' => 'auto', 'provider' => 'wallet'],
        ]);

        $html = $this->render($store);

        $this->assertStringContainsString(route('publicView', $store->slug), $html);
        $this->assertStringContainsString('Add to Wallet', $html);
    }

    public function test_auto_mode_hides_without_an_active_program(): void
    {
        $store = Store::factory()->create(); // no LoyaltyProgram row at all

        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'loyalty', 'url' => null,
            'config' => ['mode' => 'auto'],
        ]);

        $this->assertSame('', trim($this->render($store)));
    }

    public function test_inactive_program_does_not_count(): void
    {
        $store = Store::factory()->create();
        LoyaltyProgram::create([
            'store_id' => $store->id, 'active' => false, 'type' => 'stamps',
            'earn_rate' => 1, 'reward_threshold' => 10, 'reward_description' => 'Free item',
        ]);

        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'loyalty', 'url' => null,
            'config' => ['mode' => 'auto'],
        ]);

        $this->assertSame('', trim($this->render($store)));
    }

    public function test_url_mode_keeps_the_owner_typed_destination(): void
    {
        $store = Store::factory()->create();

        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'loyalty', 'label' => 'Rewards', 'url' => null,
            'config' => ['mode' => 'url', 'url' => 'https://external.example/rewards'],
        ]);

        $html = $this->render($store);
        $this->assertStringContainsString('href="https://external.example/rewards"', $html);
        $this->assertStringContainsString('Rewards', $html);
    }

    public function test_no_url_degrades_to_nothing(): void
    {
        $store = Store::factory()->create();
        BioBlock::factory()->create(['store_id' => $store->id, 'type' => 'loyalty', 'url' => null, 'config' => []]);

        $this->assertSame('', trim($this->render($store)));
    }
}
