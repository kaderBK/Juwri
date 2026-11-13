<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Juwri\Core\Models\Store;
use Juwri\Modules\Biolink\Models\BioBlock;
use Juwri\Modules\Biolink\Services\BioLinkService;
use Tests\TestCase;

/**
 * bio-t15 Lane A / A10: the `event` block (SPEC §2 A10) — title + date +
 * an SSR-computed countdown pill (no client clock, same days/hrs/min calc
 * as the countdown FAMILY layout) + an RSVP CTA, or the existing `capture`
 * block reused inline when `capture:true`.
 */
class BioLinkEventBlockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function svc(): BioLinkService
    {
        return app(BioLinkService::class);
    }

    private function render(Store $store): string
    {
        $row = $this->svc()->blocksFor($store->id)[0];

        return (string) view('bio._blocks.event', ['block' => $row])->render();
    }

    public function test_future_date_renders_the_countdown_digits_and_url_cta(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 24, 10, 0, 0, 'UTC'));

        $store = Store::factory()->create();
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'event', 'label' => 'Summer Launch Party',
            'config' => ['date' => '2026-08-25 12:00:00', 'location' => 'Rooftop Terrace', 'url' => 'https://rsvp.example/x'],
        ]);

        $html = $this->render($store);

        $this->assertStringContainsString('Summer Launch Party', $html);
        $this->assertStringContainsString('Rooftop Terrace', $html);
        $this->assertStringContainsString('bio-event__digits', $html);
        $this->assertStringContainsString('href="https://rsvp.example/x"', $html);
        $this->assertStringContainsString('RSVP', $html);
    }

    public function test_past_date_renders_no_countdown_digits(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 24, 10, 0, 0, 'UTC'));

        $store = Store::factory()->create();
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'event', 'label' => 'Past Event',
            'config' => ['date' => '2020-01-01 00:00:00'],
        ]);

        $html = $this->render($store);
        $this->assertStringNotContainsString('bio-event__digits', $html);
        $this->assertStringContainsString('Past Event', $html);
    }

    public function test_capture_true_renders_the_inline_capture_form_instead_of_the_url_cta(): void
    {
        $store = Store::factory()->create();
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'event', 'label' => 'Tasting Night',
            'config' => ['capture' => true, 'url' => 'https://should-not-show.example'],
        ]);

        $html = $this->render($store);

        $this->assertStringContainsString('bio-capture', $html);
        $this->assertStringContainsString('/subscribe', $html);
        $this->assertStringNotContainsString('https://should-not-show.example', $html);
    }

    public function test_no_title_and_no_date_degrades_to_nothing(): void
    {
        $store = Store::factory()->create();
        BioBlock::factory()->create(['store_id' => $store->id, 'type' => 'event', 'label' => null, 'config' => []]);

        $this->assertSame('', trim($this->render($store)));
    }
}
