<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Biolink;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Biolink\Models\BioBlock;
use Juwri\Modules\Biolink\Services\BioLinkService;
use Tests\TestCase;

/**
 * bio-t15 Lane A / A12: the cross-cutting `config.schedule` window/day-of-week
 * visibility gate (SPEC §2 A12) applies additively on top of the existing DB
 * visible_from/visible_until columns — a block must clear BOTH. Powers
 * happy-hour (daily HH:MM recurring window, incl. overnight) and seasonal
 * (absolute date window) auto show/hide. Validated generically in
 * BioLinkController::cleanConfig() for ANY block type.
 */
class BioLinkScheduleGateTest extends TestCase
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

    private function typeOf(array $rows, string $type): bool
    {
        return (bool) array_filter($rows, fn ($r) => $r['type'] === $type);
    }

    /** A store pinned to UTC so Carbon::setTestNow(...,'UTC') lines up directly with store_tz(). */
    private function utcStore(): Store
    {
        $store = Store::factory()->create();
        StoreOption::updateStoreOption($store->id, 'timezone', 'UTC');

        return $store;
    }

    public function test_days_restriction_hides_the_block_on_the_wrong_weekday(): void
    {
        // 2026-08-24 is a Monday (dayOfWeek=1).
        Carbon::setTestNow(Carbon::create(2026, 8, 24, 12, 0, 0, 'UTC'));
        $store = $this->utcStore();

        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'announcement', 'label' => 'Weekend only',
            'config' => ['schedule' => ['days' => [0, 6]]], // Sun/Sat only
        ]);

        $this->assertFalse($this->typeOf($this->svc()->blocksFor($store->id), 'announcement'));
    }

    public function test_days_restriction_shows_the_block_on_the_right_weekday(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 24, 12, 0, 0, 'UTC')); // Monday = 1
        $store = $this->utcStore();

        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'announcement',
            'config' => ['schedule' => ['days' => [1, 2, 3, 4, 5]]], // weekdays
        ]);

        $this->assertTrue($this->typeOf($this->svc()->blocksFor($store->id), 'announcement'));
    }

    public function test_daily_hhmm_window_gates_a_happy_hour_block(): void
    {
        $store = $this->utcStore();

        Carbon::setTestNow(Carbon::create(2026, 8, 24, 18, 0, 0, 'UTC')); // inside 17:00-19:00
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'announcement', 'label' => 'Happy Hour',
            'config' => ['schedule' => ['start_at' => '17:00', 'end_at' => '19:00']],
        ]);
        $this->assertTrue($this->typeOf($this->svc()->blocksFor($store->id), 'announcement'));

        Carbon::setTestNow(Carbon::create(2026, 8, 24, 20, 0, 0, 'UTC')); // outside
        $this->assertFalse($this->typeOf($this->svc()->blocksFor($store->id), 'announcement'));
    }

    public function test_overnight_hhmm_window_bleeds_past_midnight(): void
    {
        $store = $this->utcStore();
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'announcement',
            'config' => ['schedule' => ['start_at' => '22:00', 'end_at' => '02:00']],
        ]);

        Carbon::setTestNow(Carbon::create(2026, 8, 25, 1, 0, 0, 'UTC')); // 01:00 — inside the overnight window
        $this->assertTrue($this->typeOf($this->svc()->blocksFor($store->id), 'announcement'));

        Carbon::setTestNow(Carbon::create(2026, 8, 25, 10, 0, 0, 'UTC')); // 10:00 — outside
        $this->assertFalse($this->typeOf($this->svc()->blocksFor($store->id), 'announcement'));
    }

    public function test_absolute_date_window_gates_a_seasonal_block(): void
    {
        $store = $this->utcStore();
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'announcement', 'label' => 'Ramadan hours',
            'config' => ['schedule' => ['start_at' => '2026-08-20', 'end_at' => '2026-08-30']],
        ]);

        Carbon::setTestNow(Carbon::create(2026, 8, 24, 12, 0, 0, 'UTC')); // inside
        $this->assertTrue($this->typeOf($this->svc()->blocksFor($store->id), 'announcement'));

        Carbon::setTestNow(Carbon::create(2026, 9, 1, 12, 0, 0, 'UTC')); // after
        $this->assertFalse($this->typeOf($this->svc()->blocksFor($store->id), 'announcement'));
    }

    public function test_no_schedule_key_is_always_visible(): void
    {
        $store = $this->utcStore();
        BioBlock::factory()->create(['store_id' => $store->id, 'type' => 'announcement', 'config' => []]);

        $this->assertTrue($this->typeOf($this->svc()->blocksFor($store->id), 'announcement'));
    }

    public function test_editor_blocks_and_render_contract_expose_scheduled_true_for_a_config_window(): void
    {
        $store = $this->utcStore();
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'announcement',
            'config' => ['schedule' => ['start_at' => '17:00', 'end_at' => '19:00']],
        ]);

        $editor = $this->svc()->editorBlocks($store->id, null)[0];
        $this->assertTrue($editor['scheduled']);

        Carbon::setTestNow(Carbon::create(2026, 8, 24, 18, 0, 0, 'UTC')); // inside the window
        $render = $this->svc()->blocksFor($store->id)[0];
        $this->assertTrue($render['scheduled']);
    }

    public function test_malformed_schedule_bound_never_hides_the_block(): void
    {
        $store = $this->utcStore();
        BioBlock::factory()->create([
            'store_id' => $store->id, 'type' => 'announcement',
            'config' => ['schedule' => ['start_at' => 'not-a-date']],
        ]);

        // The controller sanitizer normally strips this before it is ever stored,
        // but the render gate is defensive on its own too — never crash/hide.
        $this->assertTrue($this->typeOf($this->svc()->blocksFor($store->id), 'announcement'));
    }

    /** @return array{0: User, 1: Store} */
    private function store(): array
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now(), 'user_type' => 'user']);
        $title = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1',
        ]);

        return [$owner, $store];
    }

    public function test_controller_sanitizer_keeps_valid_schedule_and_drops_garbage_through_the_real_route(): void
    {
        [$owner, $store] = $this->store();
        config(['settings.admin_allow_biolink' => true]);
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);

        $resp = $this->actingAs($owner)->postJson(route('biolink.blocks.store'), [
            'type' => 'link', 'label' => 'Special',
            'config' => ['schedule' => [
                'start_at' => '17:00', 'end_at' => '19:00',
                'days' => ['1', '2', 99, -1, '1'], // strings, dupes, out-of-range
            ]],
        ]);

        $resp->assertOk();
        $saved = BioBlock::where('store_id', $store->id)->where('type', 'link')->first();
        $this->assertSame('17:00', $saved->config['schedule']['start_at']);
        $this->assertSame('19:00', $saved->config['schedule']['end_at']);
        $this->assertSame([1, 2], $saved->config['schedule']['days']);
    }

    public function test_controller_sanitizer_drops_an_entirely_invalid_schedule_key(): void
    {
        [$owner, $store] = $this->store();
        config(['settings.admin_allow_biolink' => true]);
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);

        $resp = $this->actingAs($owner)->postJson(route('biolink.blocks.store'), [
            'type' => 'link', 'label' => 'X',
            'config' => ['schedule' => ['start_at' => 'garbage', 'days' => ['nope']]],
        ]);

        $resp->assertOk();
        $saved = BioBlock::where('store_id', $store->id)->where('type', 'link')->first();
        $this->assertArrayNotHasKey('schedule', $saved->config);
    }
}
