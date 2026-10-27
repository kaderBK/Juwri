<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Modules\Biolink\Models\BioBlock;
use Juwri\Modules\Biolink\Services\BioLinkService;
use Tests\TestCase;

/**
 * bio-t15 Lane A / A1: the `hours` block resolves its week + open-now status
 * server-side (SSR, no client clock — SPEC §2 A1). `source:'manual'` keeps the
 * owner-typed week; `source:'auto'` reads the platform's existing structured-hours
 * seam (OpeningHoursService, via an active branch's `hours` json or the store's
 * free-text `timing`). Renders the rich week card when data resolves, degrades to
 * nothing when it does not, and stays backward-compatible with the legacy
 * {rows,title} shape already seeded by starter templates / pre-run blocks.
 */
class BioLinkHoursBlockTest extends TestCase
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

    public function test_manual_week_is_kept_and_open_now_is_computed(): void
    {
        // Monday 10:00 UTC — inside the Monday 09:00-17:00 shift.
        Carbon::setTestNow(Carbon::create(2026, 8, 24, 10, 0, 0, 'UTC'));

        $store = Store::factory()->create();
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'hours', 'label' => 'Hours',
            'config' => [
                'source' => 'manual', 'tz' => 'UTC',
                'week' => ['mon' => [['09:00', '17:00']], 'tue' => []],
            ],
        ]);

        $cfg = $this->svc()->blocksFor($store->id)[0]['config'];

        $this->assertSame([['09:00', '17:00']], $cfg['week']['mon']);
        $this->assertSame([], $cfg['week']['tue']);
        $this->assertTrue($cfg['_status']['open']);
        $this->assertSame('mon', $cfg['_status']['today']);
    }

    public function test_manual_week_reports_closed_outside_the_shift(): void
    {
        // Monday 20:00 UTC — after the 09:00-17:00 shift closes.
        Carbon::setTestNow(Carbon::create(2026, 8, 24, 20, 0, 0, 'UTC'));

        $store = Store::factory()->create();
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'hours',
            'config' => ['source' => 'manual', 'tz' => 'UTC', 'week' => ['mon' => [['09:00', '17:00']]]],
        ]);

        $cfg = $this->svc()->blocksFor($store->id)[0]['config'];
        $this->assertFalse($cfg['_status']['open']);
    }

    public function test_overnight_shift_is_open_past_midnight(): void
    {
        // Tuesday 01:00 UTC, monday's shift runs 22:00-03:00 (overnight bleed).
        Carbon::setTestNow(Carbon::create(2026, 8, 25, 1, 0, 0, 'UTC'));

        $store = Store::factory()->create();
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'hours',
            'config' => ['source' => 'manual', 'tz' => 'UTC', 'week' => ['mon' => [['22:00', '03:00']]]],
        ]);

        $cfg = $this->svc()->blocksFor($store->id)[0]['config'];
        $this->assertTrue($cfg['_status']['open'], 'yesterday\'s overnight shift should still read open just after midnight');
        $this->assertSame('tue', $cfg['_status']['today']);
    }

    public function test_malformed_manual_week_entries_are_dropped(): void
    {
        $store = Store::factory()->create();
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'hours',
            'config' => [
                'source' => 'manual',
                'week' => [
                    'mon' => [['09:00', '17:00'], ['not-a-time', '20:00'], 'garbage'],
                    'notaday' => [['09:00', '17:00']],
                ],
            ],
        ]);

        $cfg = $this->svc()->blocksFor($store->id)[0]['config'];
        $this->assertSame([['09:00', '17:00']], $cfg['week']['mon']); // malformed pair dropped, valid kept
        $this->assertArrayNotHasKey('notaday', $cfg['week']);
        $this->assertSame(array_keys($cfg['week']), BioLinkService::HOURS_DAYS);
    }

    public function test_auto_source_resolves_from_active_branch_hours(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 24, 10, 0, 0, 'UTC'));

        $store = Store::factory()->create();
        Branch::factory()->create([
            'store_id' => $store->id, 'active' => true, 'timezone' => 'UTC',
            'hours' => ['mon' => ['open' => '08:00', 'close' => '18:00', 'closed' => false], 'tue' => ['closed' => true]],
        ]);
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'hours',
            'config' => ['source' => 'auto', 'tz' => 'UTC'],
        ]);

        $cfg = $this->svc()->blocksFor($store->id)[0]['config'];
        $this->assertSame([['08:00', '18:00']], $cfg['week']['mon']);
        $this->assertSame([], $cfg['week']['tue']);
        $this->assertTrue($cfg['_status']['open']);
    }

    public function test_auto_source_with_no_data_anywhere_leaves_the_week_empty(): void
    {
        $store = Store::factory()->create(['timing' => null]);
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'hours',
            'config' => ['source' => 'auto'],
        ]);

        $cfg = $this->svc()->blocksFor($store->id)[0]['config'];
        foreach (BioLinkService::HOURS_DAYS as $day) {
            $this->assertSame([], $cfg['week'][$day] ?? null);
        }
        $this->assertNull($cfg['_status']['open']);

        // and the render degrades to nothing — never a fake "closed all week" card.
        $html = trim((string) view('bio._blocks.hours', ['block' => $this->svc()->blocksFor($store->id)[0]])->render());
        $this->assertSame('', $html);
    }

    public function test_legacy_rows_shape_still_renders(): void
    {
        $store = Store::factory()->create();
        $block = BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'hours', 'label' => 'Hours',
            'config' => ['rows' => [['label' => 'Mon-Fri', 'value' => '9-5']]],
        ]);

        $row = $this->svc()->blocksFor($store->id)[0];
        $html = (string) view('bio._blocks.hours', ['block' => $row])->render();

        $this->assertStringContainsString('Mon-Fri', $html);
        $this->assertStringContainsString('9-5', $html);
    }

    public function test_rich_week_card_renders_status_pill_and_escapes_note(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 24, 10, 0, 0, 'UTC'));

        $store = Store::factory()->create();
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'hours', 'label' => 'Store Hours',
            'config' => [
                'source' => 'manual', 'tz' => 'UTC', 'show_status' => true,
                'week' => ['mon' => [['09:00', '17:00']]],
                'note' => '<script>alert(1)</script>',
            ],
        ]);

        $row = $this->svc()->blocksFor($store->id)[0];
        $html = (string) view('bio._blocks.hours', ['block' => $row])->render();

        $this->assertStringContainsString('Open Now', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
