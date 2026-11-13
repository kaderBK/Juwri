<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Modules\Biolink\Models\BioBlock;
use Juwri\Modules\Biolink\Services\BioLinkService;
use Tests\TestCase;

/**
 * bio-t15 Lane A / A3: the `reservation` block (SPEC §2 A3) enhances the
 * pre-existing `reservation` type in place with mode:'url'|'whatsapp',
 * `whatsapp` and `party_note`. Link/WhatsApp only — no booking-module
 * dependency (owner decision). Backward-compatible with the legacy
 * {note,url} shape; degrades to nothing when no destination resolves
 * (the pre-run partial always rendered a dead href="#").
 */
class BioLinkReservationBlockTest extends TestCase
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

        return (string) view('bio._blocks.reservation', ['block' => $row])->render();
    }

    public function test_mode_url_renders_the_configured_link(): void
    {
        $store = Store::factory()->create();
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'reservation', 'label' => 'Book a Table',
            'config' => ['mode' => 'url', 'url' => 'https://booking.example/table', 'party_note' => 'Up to 8 guests'],
        ]);

        $html = $this->render($store);

        $this->assertStringContainsString('href="https://booking.example/table"', $html);
        $this->assertStringContainsString('Book a Table', $html);
        $this->assertStringContainsString('Up to 8 guests', $html);
    }

    public function test_mode_whatsapp_builds_a_prefilled_wame_link(): void
    {
        $store = Store::factory()->create();
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'reservation',
            'config' => ['mode' => 'whatsapp', 'whatsapp' => '15551234567', 'party_note' => 'party of 4'],
        ]);

        $html = $this->render($store);

        $this->assertStringContainsString('https://api.whatsapp.com/send?phone=15551234567', $html);
        $this->assertStringContainsString('text=', $html);
        $this->assertStringContainsString('target="_blank"', $html);
    }

    public function test_legacy_note_and_url_shape_still_renders(): void
    {
        $store = Store::factory()->create();
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'reservation', 'label' => 'Reserve',
            'url' => 'https://legacy.example/book',
            'config' => ['note' => 'Walk-ins welcome'],
        ]);

        $html = $this->render($store);

        $this->assertStringContainsString('href="https://legacy.example/book"', $html);
        $this->assertStringContainsString('Walk-ins welcome', $html);
    }

    public function test_no_destination_degrades_to_nothing(): void
    {
        $store = Store::factory()->create();
        BioBlock::factory()->create(['store_id' => $store->id, 'type' => 'reservation', 'url' => null, 'config' => []]);

        $this->assertSame('', trim($this->render($store)));
    }

    public function test_whatsapp_mode_without_a_number_degrades_to_nothing(): void
    {
        $store = Store::factory()->create();
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'reservation', 'url' => null,
            'config' => ['mode' => 'whatsapp'],
        ]);

        $this->assertSame('', trim($this->render($store)));
    }
}
